<?php
$finder=PhpCsFixer\Finder::create()->in([__DIR__.'/src',__DIR__.'/tests',__DIR__.'/migrations']);
return (new PhpCsFixer\Config())->setRules(['@Symfony'=>true,'array_syntax'=>['syntax'=>'short']])->setFinder($finder)->setCacheFile(__DIR__.'/var/.php-cs-fixer.cache');
