# BDN Index

The BDN Index is a monthly index that aggregates data about stories from WordPress, Google Analytics, Facebook.

![screenshot](screenshot.png)

## Before you begin

- Make sure that the Google APIs Client Library for PHP is installed and in your include_path. [Here's how](https://developers.google.com/api-client-library/php/start/installation).
- Make sure that the array in users.php correctly maps the user ids from WordPress to the correct names and desks.
- We have special event listeners on our Google Analytics set-up 


## To run the report

Visit the directory root. By default, it will look at the previous calendar month's period. You can specify a specific period by appending `?period=YYYY-MM` to the url.
