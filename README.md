**deplo.io PHP Image Uploader (Nine S3 + DB + Redis)**

Minimalistische PHP-App zum Hochladen von Bildern in Nine Object Storage (S3), mit Metadaten-Register in MySQL und Redis als Cache.
Beim ersten Start werden fehlende Tabellen automatisch angelegt (optional kann die App – falls der DB-User das darf – die Datenbank anlegen).
Uploads, Löschen & Listing sind über eine simple Web-UI verfügbar.

**Features**
- Upload von JPEG/PNG/GIF/WebP → Speicherung als S3-Objekt (privat)
- DB-Register (Dateiname, MIME, Größe, Zeitstempel)
- Löschen entfernt DB-Eintrag und S3-Objekt
- Redis-Cache für die Liste (invalidiert bei Upload/Löschen, TTL 30 s)
- „RAW“-Debug unten auf der Seite (DB-Rows, Redis-Status/Keys/TTL); Cache-Items enthalten cached_at
- Presigned-URLs (ca. 15 Min gültig) zum Anzeigen/Öffnen der Bilder
- TLS/CA-Unterstützung für MySQL & Redis

**Setup**

- App (idealerweise in neuem Projekt) erstellen in Deploio mit Git-Repo https://github.com/thomhug/deploio-mysql-redis-s3/
- Datenbank erstellen (im selben Projekt)
- Redis erstellen
- S3 Bucket erstellen, Bucket user erstellen, Bucket user erlauben auf den neuen Bucket zuzugreifen
- Build Variablen setzen 1:1 [yaml](https://github.com/thomhug/deploio-mysql-redis-s3/blob/main/buildEnv.yaml):

  <pre>
    ---
    - name: BP_COMPOSER_INSTALL_OPTIONS
      value: "--ignore-platform-reqs"
    - name: BP_PHP_WEB_DIR
      value: public
  </pre>

- Environment Variablen setzen via Application - Konfiguration - YAML bearbeiten - mit den Credentials von deinen neuen Services [yaml](https://github.com/thomhug/deploio-mysql-redis-s3/blob/main/env-sample.yaml): 

  <pre>
---
- name: ALLOW_DB_CREATE
  value: 'false'
- name: DATABASE_URL
  value: mysql://USER:PASS@FQDN:3306/DBNAME
- name: DB_CHARSET
  value: utf8mb4
- name: DB_SSL_CA_PEM
  value: |-
    -----BEGIN CERTIFICATE-----
    ...MySQL CA PEM...
    -----END CERTIFICATE-----
- name: REDIS_URL
  value: rediss://:PASSWORD@FQDN:6379/0
- name: REDIS_CA_PEM
  value: |-
    -----BEGIN CERTIFICATE-----
    ...Redis CA PEM...
    -----END CERTIFICATE-----
- name: S3_ENDPOINT
  value: https://esXX.objects.nineapis.ch
- name: S3_REGION
  value: us-east-1
- name: S3_BUCKET
  value: MEIN_BUCKET
- name: S3_ACCESS_KEY
  value: AKIA...
- name: S3_SECRET_KEY
  value: ...
- name: S3_USE_PATH_STYLE
  value: 'true'
  </pre>
