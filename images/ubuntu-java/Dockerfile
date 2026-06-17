FROM ubuntu:22.04
ENV DEBIAN_FRONTEND=noninteractive

# Install common terminal utilities, Java 17 JDK, Maven, and code-server
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
    openjdk-17-jdk \
    maven \
    && apt-get clean && rm -rf /var/lib/apt/lists/* \
    && curl -fsSL https://code-server.dev/install.sh | sh

# Create a non-root developer user with sudo privileges
RUN useradd -m -s /bin/bash developer && \
    echo "developer ALL=(ALL) NOPASSWD:ALL" >> /etc/sudoers

USER developer
WORKDIR /home/developer

EXPOSE 8080

COPY --chown=developer:developer entrypoint.sh /home/developer/entrypoint.sh
RUN chmod +x /home/developer/entrypoint.sh

ENTRYPOINT ["/home/developer/entrypoint.sh"]
