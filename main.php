<?php

use ZM\Annotation\AnnotationParser;
use ZM\Plugin\ZMPlugin;

$zm = new ZMPlugin(__DIR__);

$zm->onPluginLoad(function (AnnotationParser $parser) {

});

return $zm;
