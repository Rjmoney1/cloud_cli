#!/bin/bash
# Start code-server
export HOME="/home/$USER_NAME"
exec su -p -s /bin/bash -c "exec code-server --bind-addr 0.0.0.0:8080 --auth none" "$USER_NAME"
