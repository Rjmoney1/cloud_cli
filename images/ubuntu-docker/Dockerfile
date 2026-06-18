FROM ubuntu:22.04
ENV DEBIAN_FRONTEND=noninteractive

# Install common utilities, Docker engine packages, openssh-server, and code-server
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
    openssh-server \
    && curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /usr/share/keyrings/docker-archive-keyring.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/docker-archive-keyring.gpg] https://download.docker.com/linux/ubuntu jammy stable" > /etc/apt/sources.list.d/docker.list \
    && apt-get update \
    && apt-get install -y docker-ce docker-ce-cli containerd.io \
    && apt-get clean && rm -rf /var/lib/apt/lists/* \
    && curl -fsSL https://code-server.dev/install.sh | sh

# Configure SSH daemon settings
RUN mkdir -p /var/run/sshd && \
    echo "PasswordAuthentication yes" >> /etc/ssh/sshd_config

# Create a non-root developer user with sudo privileges, add to docker group
RUN useradd -m -s /bin/bash developer && \
    echo "developer ALL=(ALL) NOPASSWD:ALL" >> /etc/sudoers && \
    usermod -aG docker developer

# Copy entrypoint script
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
