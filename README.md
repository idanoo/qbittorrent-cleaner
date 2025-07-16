# qbittorrent-cleaner

Hacked up script used to purge orphaned files from qBitTorrent.    
Forget to tick 'Remove content'?    
Misconfigured Unpackarr and have leftover uncompressed files?    
    
Me too!    
    
Install php & php-curl. Danger script is dangerous. 

Will delete files. 
    
```php
QBT_HOST=http://127.0.0.01:8080 \
 QBT_USER=admin \
 QBT_PASS=pass \
 php clean.php "/path/to/downloads/folder"
```
