#!/bin/bash

# Default values if not passed
USER_NAME=${USER_NAME:-developer}
USER_PASSWORD_HASH=${USER_PASSWORD_HASH:-}

# 1. Create the user dynamically if they don't exist
if ! id -u "$USER_NAME" >/dev/null 2>&1; then
    if [ -n "$USER_PASSWORD_HASH" ]; then
        # Create user with the encrypted password hash
        useradd -m -s /bin/bash -p "$USER_PASSWORD_HASH" "$USER_NAME"
    else
        # Create user without password
        useradd -m -s /bin/bash "$USER_NAME"
    fi
    
    # Add to sudoers group (requires password to run sudo)
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
    # Clear authorized_keys if empty
    true > "$USER_SSH_DIR/authorized_keys"
fi
chmod 700 "$USER_SSH_DIR"
chmod 600 "$USER_SSH_DIR/authorized_keys"
chown -R "$USER_NAME:$USER_NAME" "$USER_SSH_DIR"


# 3. Start the SSH server daemon
ssh-keygen -A
/usr/sbin/sshd

# 4. Start code-server as the target student user
export HOME="/home/$USER_NAME"
exec su -p -s /bin/bash -c "exec code-server --bind-addr 0.0.0.0:8080 --auth none" "$USER_NAME"
