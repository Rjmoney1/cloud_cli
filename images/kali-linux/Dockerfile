FROM kalilinux/kali-rolling
ENV DEBIAN_FRONTEND=noninteractive

# Install common utilities, pentesting tools, and openssh-server
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
    nmap \
    hydra \
    sqlmap \
    nikto \
    john \
    ncat \
    openssh-server \
    && apt-get clean && rm -rf /var/lib/apt/lists/* \
    && curl -fsSL https://code-server.dev/install.sh | sh

# Configure SSH daemon settings
RUN mkdir -p /var/run/sshd && \
    echo "PasswordAuthentication yes" >> /etc/ssh/sshd_config

# Create a non-root developer user with sudo privileges
RUN useradd -m -s /bin/bash developer && \
    echo "developer ALL=(ALL) NOPASSWD:ALL" >> /etc/sudoers

# Copy entrypoint script
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
