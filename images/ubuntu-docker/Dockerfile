FROM ubuntu:22.04
ENV DEBIAN_FRONTEND=noninteractive

# Install common utilities, Docker engine packages, and code-server
RUN apt-get update && apt-get install -y \
    curl \
    git \
    sudo \
    wget \
    gnupg \
    build-essential \
    iproute2 \
    iputils-ping \
    net-tools \
    nano \
    unzip \
    ca-certificates \
    apt-transport-https \
    software-properties-common \
    && curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /usr/share/keyrings/docker-archive-keyring.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/docker-archive-keyring.gpg] https://download.docker.com/linux/ubuntu jammy stable" > /etc/apt/sources.list.d/docker.list \
    && apt-get update \
    && apt-get install -y docker-ce docker-ce-cli containerd.io \
    && apt-get clean && rm -rf /var/lib/apt/lists/* \
    && curl -fsSL https://code-server.dev/install.sh | sh

# Create a non-root developer user with sudo privileges, add to docker group
RUN useradd -m -s /bin/bash developer && \
    echo "developer ALL=(ALL) NOPASSWD:ALL" >> /etc/sudoers && \
    usermod -aG docker developer

USER developer
WORKDIR /home/developer

EXPOSE 8080

COPY --chown=developer:developer entrypoint.sh /home/developer/entrypoint.sh
RUN chmod +x /home/developer/entrypoint.sh

ENTRYPOINT ["/home/developer/entrypoint.sh"]
