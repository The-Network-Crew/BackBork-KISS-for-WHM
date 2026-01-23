#!/usr/local/cpanel/3rdparty/bin/perl
# BackBork KISS - cPanel Transport Helper
# Bridges PHP to cPanel's internal Cpanel::Transport::Files module
# Handles upload, download, list, and delete operations directly via the Perl API
#
# BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
# Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
# https://github.com/The-Network-Crew/BackBork-KISS-Plugin-for-WHM/
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU Affero General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU Affero General Public License for more details.
#
# You should have received a copy of the GNU Affero General Public License
# along with this program. If not, see <https://www.gnu.org/licenses/>.
#
# Usage:
#   cpanel_transport.pl --action=ls --transport=<id> [--path=<path>]
#   cpanel_transport.pl --action=upload --transport=<id> --local=<path> [--remote=<path>]
#   cpanel_transport.pl --action=download --transport=<id> --remote=<path> --local=<path>
#   cpanel_transport.pl --action=delete --transport=<id> --path=<path>
#   cpanel_transport.pl --action=mkdir --transport=<id> --path=<path>
#
# Output: JSON to stdout, debug to stderr

use strict;
use warnings;

# Use cPanel's Perl libraries
use lib '/usr/local/cpanel';

use Cpanel::JSON             ();
use Cpanel::Backup::Config   ();
use Cpanel::Backup::Transport();
use Cpanel::Transport::Files ();
use File::Basename           ();

# Parse command line arguments
my %args;
foreach my $arg (@ARGV) {
    if ($arg =~ /^--(\w+)=(.*)$/) {
        $args{$1} = $2;
    }
}

# Validate required arguments
my $action = $args{'action'} || '';
my $transport_id = $args{'transport'} || '';

if (!$action) {
    print_json({ success => 0, message => 'Missing required argument: --action' });
    exit 1;
}

if (!$transport_id) {
    print_json({ success => 0, message => 'Missing required argument: --transport' });
    exit 1;
}

# Get transport configuration from WHM backup config
my $transport_config = get_transport_config($transport_id);
if (!$transport_config) {
    print_json({ success => 0, message => "Transport '$transport_id' not found or not enabled" });
    exit 1;
}

# Execute requested action
if ($action eq 'ls') {
    do_ls($transport_config, $args{'path'} || '');
}
elsif ($action eq 'upload') {
    my $local = $args{'local'} || '';
    if (!$local) {
        print_json({ success => 0, message => 'Missing required argument: --local for upload' });
        exit 1;
    }
    do_upload($transport_config, $local, $args{'remote'} || '');
}
elsif ($action eq 'download') {
    my $remote = $args{'remote'} || '';
    my $local = $args{'local'} || '';
    if (!$remote || !$local) {
        print_json({ success => 0, message => 'Missing required arguments: --remote and --local for download' });
        exit 1;
    }
    do_download($transport_config, $remote, $local);
}
elsif ($action eq 'delete') {
    my $path = $args{'path'} || '';
    if (!$path) {
        print_json({ success => 0, message => 'Missing required argument: --path for delete' });
        exit 1;
    }
    do_delete($transport_config, $path);
}
elsif ($action eq 'mkdir') {
    my $path = $args{'path'} || '';
    if (!$path) {
        print_json({ success => 0, message => 'Missing required argument: --path for mkdir' });
        exit 1;
    }
    do_mkdir($transport_config, $path);
}
else {
    print_json({ success => 0, message => "Unknown action: $action" });
    exit 1;
}

exit 0;

# ============================================================================
# TRANSPORT CONFIGURATION
# ============================================================================

sub get_transport_config {
    my ($id) = @_;
    
    warn "cpanel_transport.pl: Looking for transport ID: $id\n";
    
    # Use Cpanel::Backup::Transport to get enabled destinations
    my $transports = Cpanel::Backup::Transport->new();
    my $transport_configs = $transports->get_enabled_destinations();
    
    if (!$transport_configs) {
        warn "cpanel_transport.pl: Failed to get transport configs\n";
        return;
    }
    
    # Log available destination IDs for debugging
    my @available_ids = keys %$transport_configs;
    warn "cpanel_transport.pl: Available destination IDs: " . join(', ', @available_ids) . "\n";
    
    # Find the matching config
    my $config = $transport_configs->{$id};
    
    if (!$config) {
        warn "cpanel_transport.pl: No matching destination found for ID: $id\n";
        return;
    }
    
    warn "cpanel_transport.pl: Found destination, type=" . ($config->{'type'} || 'Unknown') . 
         ", path=" . ($config->{'path'} || 'none') . "\n";
    
    return $config;
}

sub get_transport {
    my ($config) = @_;
    my $type = $config->{'type'};
    return Cpanel::Transport::Files->new($type, $config);
}

# ============================================================================
# UPLOAD FILE
# ============================================================================

sub do_upload {
    my ($config, $local_path, $remote_path) = @_;
    
    my $base_path = $config->{'path'} || '';
    my $filename = File::Basename::basename($local_path);
    
    warn "cpanel_transport.pl: do_upload local='$local_path' remote='$remote_path' base='$base_path'\n";
    
    # Verify local file exists
    if (!-f $local_path) {
        print_json({ success => 0, message => "Local file not found: $local_path" });
        return;
    }
    
    # Get file size for reporting
    my $file_size = -s $local_path;
    warn "cpanel_transport.pl: File size: $file_size bytes\n";
    
    eval {
        my $transport = get_transport($config);
        warn "cpanel_transport.pl: Transport object created\n";
        
        # Build full remote path
        # If remote_path provided, use it; otherwise use base_path/filename
        my $full_remote;
        if ($remote_path) {
            # If remote_path is just a directory, append filename
            if ($remote_path =~ m{/$} || $remote_path !~ m{\.[^/]+$}) {
                $full_remote = $remote_path;
                $full_remote =~ s{/$}{};
                $full_remote .= '/' . $filename;
            } else {
                $full_remote = $remote_path;
            }
            # Prepend base_path if not already included
            if ($base_path && $full_remote !~ m{^\Q$base_path\E(/|$)}) {
                $full_remote = "$base_path/$full_remote";
            }
        } else {
            # Default: base_path/filename
            $full_remote = $base_path ? "$base_path/$filename" : $filename;
        }
        
        warn "cpanel_transport.pl: Full remote path='$full_remote'\n";
        
        # Ensure parent directory exists (try to create it)
        my $remote_dir = File::Basename::dirname($full_remote);
        if ($remote_dir && $remote_dir ne '.' && $remote_dir ne $full_remote) {
            warn "cpanel_transport.pl: Ensuring remote directory exists: '$remote_dir'\n";
            eval {
                $transport->mkdir($remote_dir);
            };
            # Ignore mkdir errors - directory may already exist
            if ($@) {
                warn "cpanel_transport.pl: mkdir note (may be OK): $@\n";
            }
        }
        
        # Perform the upload
        warn "cpanel_transport.pl: Starting upload...\n";
        my $response = $transport->put($local_path, $full_remote);
        
        warn "cpanel_transport.pl: Upload response type=" . (ref $response || 'scalar') . "\n";
        
        if ($response && ref $response && $response->{'success'}) {
            warn "cpanel_transport.pl: Upload successful\n";
            print_json({
                success     => 1,
                message     => "Uploaded successfully to $full_remote",
                remote_path => $full_remote,
                file        => $filename,
                size        => $file_size
            });
        }
        elsif ($response && ref $response) {
            my $msg = $response->{'msg'} || $response->{'message'} || 'Unknown error';
            warn "cpanel_transport.pl: Upload failed: $msg\n";
            print_json({ success => 0, message => "Upload failed: $msg" });
        }
        else {
            # Some transports return true/false instead of response object
            if ($response) {
                warn "cpanel_transport.pl: Upload returned true (non-object)\n";
                print_json({
                    success     => 1,
                    message     => "Uploaded to $full_remote",
                    remote_path => $full_remote,
                    file        => $filename,
                    size        => $file_size
                });
            } else {
                warn "cpanel_transport.pl: Upload returned false/undef\n";
                print_json({ success => 0, message => "Upload failed - no response from transport" });
            }
        }
    };
    if ($@) {
        my $error = extract_error($@);
        warn "cpanel_transport.pl: Upload exception: $error\n";
        print_json({ success => 0, message => "Upload error: $error" });
    }
}

# ============================================================================
# DOWNLOAD FILE
# ============================================================================

sub do_download {
    my ($config, $remote_path, $local_path) = @_;
    
    my $base_path = $config->{'path'} || '';
    
    warn "cpanel_transport.pl: do_download remote='$remote_path' local='$local_path' base='$base_path'\n";
    
    eval {
        my $transport = get_transport($config);
        
        # Build full remote path - prepend base_path if not already included
        my $full_remote = $remote_path;
        if ($base_path && $full_remote !~ m{^\Q$base_path\E(/|$)}) {
            $full_remote = "$base_path/$full_remote";
        }
        
        warn "cpanel_transport.pl: Full remote path='$full_remote'\n";
        
        # Ensure local directory exists
        my $local_dir = File::Basename::dirname($local_path);
        if ($local_dir && !-d $local_dir) {
            warn "cpanel_transport.pl: Creating local directory: $local_dir\n";
            system("mkdir -p " . quotemeta($local_dir));
        }
        
        # Perform download
        warn "cpanel_transport.pl: Starting download...\n";
        my $response = $transport->get($full_remote, $local_path);
        
        warn "cpanel_transport.pl: Download response type=" . (ref $response || 'scalar') . "\n";
        
        # Check if file was downloaded
        if (-f $local_path) {
            my $size = -s $local_path;
            warn "cpanel_transport.pl: Download successful, size=$size\n";
            print_json({
                success    => 1,
                message    => "Downloaded successfully to $local_path",
                local_path => $local_path,
                size       => $size
            });
        }
        elsif ($response && ref $response && $response->{'success'}) {
            # Response says success but file doesn't exist?
            warn "cpanel_transport.pl: Response success but file missing\n";
            print_json({ success => 0, message => "Download reported success but file not found at $local_path" });
        }
        elsif ($response && ref $response) {
            my $msg = $response->{'msg'} || $response->{'message'} || 'Unknown error';
            warn "cpanel_transport.pl: Download failed: $msg\n";
            print_json({ success => 0, message => "Download failed: $msg" });
        }
        else {
            warn "cpanel_transport.pl: Download failed - no file created\n";
            print_json({ success => 0, message => "Download failed - file was not created" });
        }
    };
    if ($@) {
        my $error = extract_error($@);
        warn "cpanel_transport.pl: Download exception: $error\n";
        print_json({ success => 0, message => "Download error: $error" });
    }
}

# ============================================================================
# LIST FILES
# ============================================================================

sub do_ls {
    my ($config, $path) = @_;
    
    my $base_path = $config->{'path'} || '';
    warn "cpanel_transport.pl: do_ls path=" . ($path || 'default') . " base='$base_path'\n";
    
    eval {
        my $transport = get_transport($config);
        warn "cpanel_transport.pl: Transport object created\n";
        
        # Build list path - if path provided, prepend base_path; otherwise list base_path
        my $list_path;
        if ($path) {
            $list_path = ($base_path && $path !~ m{^\Q$base_path\E(/|$)}) 
                       ? "$base_path/$path" 
                       : $path;
        } else {
            $list_path = $base_path || '.';
        }
        
        warn "cpanel_transport.pl: Listing path='$list_path'\n";
        
        my $response = $transport->ls($list_path);
        
        warn "cpanel_transport.pl: ls() response type=" . (ref $response || 'scalar') . "\n";
        
        if ($response && $response->{'success'}) {
            my @files;
            my $data = $response->{'data'} || [];
            
            warn "cpanel_transport.pl: Got " . scalar(@$data) . " entries\n";
            
            foreach my $entry (@$data) {
                next unless ref $entry eq 'HASH';
                my $filename = $entry->{'filename'} || $entry->{'name'} || '';
                next if $filename =~ /^\.\.?$/;  # Skip . and ..
                
                push @files, {
                    file  => $filename,
                    size  => $entry->{'size'} || 0,
                    type  => $entry->{'type'} || ($entry->{'is_dir'} ? 'dir' : 'file'),
                    mtime => $entry->{'mtime'} || $entry->{'modified'} || 0,
                };
            }
            
            warn "cpanel_transport.pl: Returning " . scalar(@files) . " files\n";
            print_json({ 
                success => 1, 
                files   => \@files,
                path    => $list_path 
            });
        }
        else {
            my $msg = $response ? ($response->{'msg'} || 'Unknown error') : 'No response from transport';
            warn "cpanel_transport.pl: ls() failed: $msg\n";
            print_json({ success => 0, message => "Failed to list: $msg", files => [] });
        }
    };
    if ($@) {
        my $error = extract_error($@);
        warn "cpanel_transport.pl: ls() exception: $error\n";
        print_json({ success => 0, message => "List error: $error", files => [] });
    }
}

# ============================================================================
# DELETE FILE
# ============================================================================

sub do_delete {
    my ($config, $path) = @_;
    
    my $base_path = $config->{'path'} || '';
    
    warn "cpanel_transport.pl: do_delete path='$path' base='$base_path'\n";
    
    eval {
        my $transport = get_transport($config);
        
        # Build full path - prepend base_path if not already included
        my $full_path = $path;
        if ($base_path && $full_path !~ m{^\Q$base_path\E(/|$)}) {
            $full_path = "$base_path/$full_path";
        }
        
        warn "cpanel_transport.pl: Full delete path='$full_path'\n";
        
        my $response = $transport->delete($full_path);
        
        if ($response && ref $response && $response->{'success'}) {
            warn "cpanel_transport.pl: Delete successful\n";
            print_json({ success => 1, message => "Deleted: $full_path" });
        }
        elsif ($response && ref $response) {
            my $msg = $response->{'msg'} || $response->{'message'} || 'Unknown error';
            warn "cpanel_transport.pl: Delete failed: $msg\n";
            print_json({ success => 0, message => "Failed to delete: $msg" });
        }
        else {
            # Some transports return boolean
            if ($response) {
                print_json({ success => 1, message => "Deleted: $full_path" });
            } else {
                print_json({ success => 0, message => "Delete failed - no response from transport" });
            }
        }
    };
    if ($@) {
        my $error = extract_error($@);
        warn "cpanel_transport.pl: Delete exception: $error\n";
        print_json({ success => 0, message => "Delete error: $error" });
    }
}

# ============================================================================
# MAKE DIRECTORY
# ============================================================================

sub do_mkdir {
    my ($config, $path) = @_;
    
    my $base_path = $config->{'path'} || '';
    
    warn "cpanel_transport.pl: do_mkdir path='$path' base='$base_path'\n";
    
    eval {
        my $transport = get_transport($config);
        
        # Build full path - prepend base_path if not already included
        my $full_path = $path;
        if ($base_path && $full_path !~ m{^\Q$base_path\E(/|$)}) {
            $full_path = "$base_path/$full_path";
        }
        
        warn "cpanel_transport.pl: Full mkdir path='$full_path'\n";
        
        my $response = $transport->mkdir($full_path);
        
        if ($response && ref $response && $response->{'success'}) {
            print_json({ success => 1, message => "Created directory: $full_path" });
        }
        elsif ($response && ref $response) {
            my $msg = $response->{'msg'} || $response->{'message'} || 'Unknown error';
            print_json({ success => 0, message => "Failed to create directory: $msg" });
        }
        else {
            # Assume success if no error thrown
            print_json({ success => 1, message => "Created directory: $full_path" });
        }
    };
    if ($@) {
        my $error = extract_error($@);
        # Directory may already exist - not always an error
        if ($error =~ /exist/i) {
            print_json({ success => 1, message => "Directory exists: $path" });
        } else {
            warn "cpanel_transport.pl: mkdir exception: $error\n";
            print_json({ success => 0, message => "mkdir error: $error" });
        }
    }
}

# ============================================================================
# HELPER FUNCTIONS
# ============================================================================

sub extract_error {
    my ($error) = @_;
    
    # Handle various exception types from cPanel
    if (ref($error) && $error->can('get')) {
        return $error->get('msg') || $error->get('message') || "$error";
    }
    elsif (ref($error) && ref($error) eq 'HASH') {
        return $error->{'msg'} || $error->{'message'} || "$error";
    }
    elsif (ref($error)) {
        return eval { $error->message } || eval { $error->to_string } || "$error";
    }
    
    return "$error";
}

sub print_json {
    my ($data) = @_;
    print Cpanel::JSON::Dump($data) . "\n";
}
