# nets-forsendelse
A php class that converts flat file packages from the Nordic payment service provider NETS into php objects, and reversive.

The packages flat file content should be passed to the class constructor:
$packageObject = new NetsForsendelse($flatFile);
