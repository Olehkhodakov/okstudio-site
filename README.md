# OK Studio site

Static site for okstudio.com.ua: `index.html` + `assets/`.

Deploy: a CRON job on CityHost runs `deploy/deploy.php` every few minutes.
When `main` has a new commit it downloads it and updates the site files
(WordPress files are never touched). Files in `deploy/` and this README are not uploaded to the site.
