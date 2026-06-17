#!/bin/bash
# Start Nginx service
sudo service nginx start

# Start code-server
exec code-server --bind-addr 0.0.0.0:8080 --auth none
