#!/bin/bash
set -e

echo "Installing Docker from Ubuntu repositories..."
sudo apt-get install -y -qq docker.io 2>&1

echo "Starting Docker daemon..."
sudo systemctl enable docker 2>/dev/null || true
sudo systemctl start docker 2>/dev/null || {
    echo "systemctl failed, trying dockerd directly..."
    sudo nohup dockerd > /tmp/dockerd.log 2>&1 &
    sleep 5
}

echo "Adding ubuntu user to docker group..."
sudo usermod -aG docker ubuntu

echo "Docker installation complete."
docker --version
docker compose version 2>&1 || echo "compose via plugin check"
