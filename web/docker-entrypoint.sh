#!/bin/bash
set -e

# If the docker socket exists, make sure www-data has access to it
if [ -S /var/run/docker.sock ]; then
    # Get the group ID of the docker socket
    SOCKET_GID=$(stat -c '%g' /var/run/docker.sock)
    
    # Check if a group with this GID already exists in the container
    GROUP_NAME=$(getent group "$SOCKET_GID" | cut -d: -f1 || true)
    
    if [ -z "$GROUP_NAME" ]; then
        # Create a new group for docker
        groupadd -g "$SOCKET_GID" host-docker
        GROUP_NAME="host-docker"
    fi
    
    # Add www-data user to this group
    usermod -aG "$GROUP_NAME" www-data
fi

# Run the original command (apache2-foreground)
exec "$@"
