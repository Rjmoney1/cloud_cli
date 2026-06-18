#!/bin/bash

# 1. Start Docker daemon in the background (runs as root inside container)
dockerd --host=unix:///var/run/docker.sock > /tmp/dockerd.log 2>&1 &

# Wait for dockerd to start
for i in {1..10}; do
    if docker info >/dev/null 2>&1; then
        echo "Docker daemon started successfully!"
        break
    fi
    sleep 1
done

# Default values if not passed
USER_NAME=${USER_NAME:-developer}
USER_PASSWORD_HASH=${USER_PASSWORD_HASH:-}

# 2. Create the user dynamically if they don't exist
if ! id -u "$USER_NAME" >/dev/null 2>&1; then
    if [ -n "$USER_PASSWORD_HASH" ]; then
        useradd -m -s /bin/bash -p "$USER_PASSWORD_HASH" "$USER_NAME"
    else
        useradd -m -s /bin/bash "$USER_NAME"
    fi
    usermod -aG sudo "$USER_NAME"
    # Make sure user is in the docker group to access docker socket
    usermod -aG docker "$USER_NAME"
fi

# Ensure user is in the docker group just in case
usermod -aG docker "$USER_NAME"

# 3. Ensure ownership of their home directory
if [ -d "/home/$USER_NAME" ]; then
    chown -R "$USER_NAME:$USER_NAME" "/home/$USER_NAME"
    cd "/home/$USER_NAME"
else
    mkdir -p "/home/$USER_NAME"
    chown -R "$USER_NAME:$USER_NAME" "/home/$USER_NAME"
    cd "/home/$USER_NAME"
fi

# Ensure SSH directory and authorized_keys exist
USER_SSH_DIR="/home/$USER_NAME/.ssh"
mkdir -p "$USER_SSH_DIR"
if [ -n "$USER_SSH_PUBLIC_KEY" ]; then
    echo "$USER_SSH_PUBLIC_KEY" > "$USER_SSH_DIR/authorized_keys"
else
    true > "$USER_SSH_DIR/authorized_keys"
fi
chmod 700 "$USER_SSH_DIR"
chmod 600 "$USER_SSH_DIR/authorized_keys"
chown -R "$USER_NAME:$USER_NAME" "$USER_SSH_DIR"

# 4. Start the SSH server daemon
ssh-keygen -A
/usr/sbin/sshd

# 5. Start code-server as the target student user
export HOME="/home/$USER_NAME"
exec su -p -s /bin/bash -c "exec code-server --bind-addr 0.0.0.0:8080 --auth none" "$USER_NAME"
