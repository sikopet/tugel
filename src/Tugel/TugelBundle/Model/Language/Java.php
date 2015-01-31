<?php

namespace Tugel\TugelBundle\Model\Language;

use Tugel\TugelBundle\Model\Language;
use Tugel\TugelBundle\Model\Index;
use Tugel\TugelBundle\Util\Utils;

class Java extends Language {
		
	public function analyzeProvide(Index $index, $path, $file) {
		$src = file_get_contents($path . $file);
		
		preg_match_all('@(?:^|\\s)package\\s+((?:[a-zA-Z_$][a-zA-Z\\d_$]*\\.)*[a-zA-Z_$][^\\s;]*)(?:\\s|;)@', $src, $matches);
		foreach ($matches[1] as $namespace) {
			$index->addNamespace($namespace);
		}
		$ns = count($index->getNamespaces()) == 1 ? key($index->getNamespaces()) . '\\' : '';
		
		preg_match_all('@(?<!private)\\s+class\\s+([a-zA-Z_$][a-zA-Z\\d_$]*)(?:\\s|\\{)@', $src, $matches);
		foreach ($matches[1] as $class) {
			$index->addClass($ns . $class);
		}
	}
		
	public function analyzeUse(Index $index, $path, $file) {
		$src = file_get_contents($path . $file);
		preg_match_all('@(?:^|\\s)import\\s+((?:[a-zA-Z_$][a-zA-Z\\d_$]*\\.)*[a-zA-Z_$][a-zA-Z\\d_$]*)\\s*;@', $src, $matches);
		$namespaces = array();
		foreach ($matches[1] as $namespace)
			$namespaces[$namespace] = 1;
		$ns = count($namespaces) == 1 ? key($namespaces) . '.' : '';
		
		preg_match_all('@[^\\w]new\\s+([a-zA-Z_$][a-zA-Z\\d_$]*)(?:\\s|\\()@', $src, $matches);
		$classes = array();
		foreach ($matches[1] as $class)
			$classes[$ns . $class] = 1;
		
		return array(
			'namespace' => $namespaces,
			'class' => $classes,
		);
	}
	
	public function getName() {
		return 'Java';
	}
	
	public function getExtension() {
		return '.java';
	}

}
