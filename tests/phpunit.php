<?php
if (!($loader = @include __DIR__ . '/../vendor/autoload.php')) {
    die(<<<'EOT'
You must set up the project dependencies, run the following commands:
wget http://getcomposer.org/composer.phar
php composer.phar --dev install
EOT
    );
}
use Doctrine\Common\Annotations\AnnotationRegistry;
AnnotationRegistry::registerLoader(array($loader, 'loadClass'));
return $loader;
