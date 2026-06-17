#!/bin/bash
# Start Docker daemon in the background
sudo dockerd --host=unix:///var/run/docker.sock > /tmp/dockerd.log 2>&1 &

# Wait for dockerd to start
for i in {1..10}; do
    if sudo docker info >/dev/null 2>&1; then
        echo "Docker daemon started successfully!"
        break
    fi
    sleep 1
done

# Start code-server
exec code-server --bind-addr 0.0.0.0:8080 --auth none
