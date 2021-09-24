PuMuKIT OAI Bundle
==================

This bundle requires PuMuKIT version 4 or higher

```bash
composer require teltek/pumukit-oai-bundle
```

if not, add this to config/bundles.php

```
Pumukit\OaiBundle\PumukitOaiBundle::class => ['all' => true]
```

Then execute the following commands

```bash
php bin/console cache:clear
php bin/console cache:clear --env=prod
php bin/console assets:install
```
