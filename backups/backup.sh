#!/bin/bash
current_date=$(date +'%d.%m.%Y')
backup_dir="${PWD}/files"
backup_file="${backup_dir}/${current_date}.sql"

# echo "sudo mysqldump zedbitedb > ${backup_file} &"
sudo mysqldump zedbitedb > ${backup_file} &