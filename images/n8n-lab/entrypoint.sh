#!/bin/bash

# Default values if not passed
USER_NAME=${USER_NAME:-developer}
USER_PASSWORD_HASH=${USER_PASSWORD_HASH:-}

# 1. Create the user dynamically if they don't exist
if ! id -u "$USER_NAME" >/dev/null 2>&1; then
    if [ -n "$USER_PASSWORD_HASH" ]; then
        useradd -m -s /bin/bash -p "$USER_PASSWORD_HASH" "$USER_NAME"
    else
        useradd -m -s /bin/bash "$USER_NAME"
    fi
    usermod -aG sudo "$USER_NAME"
fi

# 2. Ensure ownership of their home directory
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

# 3. Start the SSH server daemon
ssh-keygen -A
/usr/sbin/sshd

# 4. Start n8n in the background as the student user
export HOME="/home/$USER_NAME"
export N8N_PORT=5678
export N8N_PATH="/n8n/$USER_NAME/"
export N8N_EDITOR_BASE_URL="/n8n/$USER_NAME/"
export WEBHOOK_URL="http://localhost/n8n/$USER_NAME/"
sleep 2
su -p -s /bin/bash -c "export HOME='/home/$USER_NAME' && export N8N_PORT=5678 && export N8N_PATH='/n8n/$USER_NAME/' && export N8N_EDITOR_BASE_URL='/n8n/$USER_NAME/' && export WEBHOOK_URL='http://localhost/n8n/$USER_NAME/' && nohup n8n start > /tmp/n8n.log 2>&1 &" "$USER_NAME"

# 5. Start code-server as the target student user
export HOME="/home/$USER_NAME"
exec su -p -s /bin/bash -c "exec code-server --bind-addr 0.0.0.0:8080 --auth none" "$USER_NAME"
