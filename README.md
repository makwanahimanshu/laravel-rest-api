# Upgrading or installing PHP on Ubuntu — PHP 8.2 and Ubuntu 22.04

--Performing the PHP installation on Ubuntu
apt-get update
sudo apt-get install php8.2
sudo apt-get install php8.2-memcached php8.2-opcache php8.2-mbstring

sudo update-alternatives --set php /usr/bin/php8.2
sudo a2dismod php8.1
sudo a2enmod php8.2
sudo systemctl restart apache2
---------------------------

Create user new field and migration
create model like : 
- php artisan make:model UserType -m
- php artisan make:model UserStatus -m

php artisan migrate:rollback --step=3