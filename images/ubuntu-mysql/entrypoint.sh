#!/bin/bash
# Start MySQL Service
sudo service mysql start

# Initialize MySQL with a test DB and user for the student if not already done
sudo mysql -e "CREATE DATABASE IF NOT EXISTS student_db;"
sudo mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY 'rootpassword';"
sudo mysql -e "CREATE USER IF NOT EXISTS 'developer'@'%' IDENTIFIED BY 'password';"
sudo mysql -e "GRANT ALL PRIVILEGES ON *.* TO 'developer'@'%' WITH GRANT OPTION;"
sudo mysql -e "FLUSH PRIVILEGES;"

# Start code-server
exec code-server --bind-addr 0.0.0.0:8080 --auth none
