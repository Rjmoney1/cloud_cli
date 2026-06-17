#!/bin/bash
set -e

echo "========================================================="
echo "   Initializing Cloud-Based Linux Lab Platform Setup     "
echo "========================================================="

# 1. Create storage directories on the host
echo "[*] Creating host storage directories..."
sudo mkdir -p /var/lib/linux-lab/isos
sudo mkdir -p /var/lib/linux-lab/homes
sudo chmod 777 /var/lib/linux-lab/isos
sudo chmod 777 /var/lib/linux-lab/homes
echo "[+] Host directories created successfully."

# 2. Build Linux Lab Environment Images
echo "[*] Building lab environment Docker images (this may take a few minutes)..."

echo "    -> Building Ubuntu 22.04 LTS..."
docker build -t lab-ubuntu ./images/ubuntu-22.04

echo "[+] Student lab environment image built."

# 3. Start Core Management Platform
echo "[*] Starting the platform services (Database, Web App, Nginx Proxy)..."
docker-compose up -d --build

echo ""
echo "========================================================="
echo "   Setup Complete! Linux Lab Platform is Active.         "
echo "========================================================="
echo "Access the dashboard here: http://localhost"
echo "Default Credentials:"
echo "   - Admin: admin / admin123"
echo "   - User:  testuser / testuser123"
echo "========================================================="
