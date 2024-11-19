# OpenvpnMFA
OpenvpnMFA panel to control users

Requirements:-
dnf install google-authenticator
dnf install qrencode
dnf install liboath liboath-devel libpskc libpskc-devel oathtool pam_oath pskctool
dnf isntall expect
dnf install python-urllib3
wget http://prdownloads.sourceforge.net/pamtester/pamtester-0.1.2.tar.gz
tar -xvzf pamtester-0.1.2.tar.gz
cd pamtester-0.1.2
./configure
make install

mkdir /var/www/html/panel/ -p
cd /var/www/html/panel/
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer
composer require sonata-project/google-authenticator
composer require endroid/qr-code


Run this comamnd to start service:-

php -S 0.0.0.0:$port -t /var/www/html/panel &
