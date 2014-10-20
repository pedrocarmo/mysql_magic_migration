mysqlmigrate
============

A shitty mysql migration tool for databases with lots of inserts every second, "without downtime".

While it should at most not work (with actually breaking something), it is NOT intended for use ANYWHERE. I'm not liable if you break everything.

============

This tool migrates a full database (with mysqldump) and then restores any records inserted in the meantime on the original database. It does it based on a crude guesstimation on how many new records you have, so it's not 100% guaranteed to work.

It may or may not lock your whole database while dumping it. It most likely will and if you're ok with this, you probably don't need this tool. I may fix that in the future.

For more detail, run the tool -- it has prompts before actually doing anything -- or read the code -- even better.

It has no checks right now, so if something breaks, it's oh so expected.
