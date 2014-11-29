<?php

namespace Zeutzheim\PpintBundle\Model\Language;

use Zeutzheim\PpintBundle\Model\Language;
use Zeutzheim\PpintBundle\Util\Utils;

use PhpParser\Parser;
use PhpParser\Lexer;

class PHP extends Language {
    
    public static function tagItems(&$tags, $items, $pattern = Utils::CAMEL_CASE_PATTERN) {
        foreach ($items as $key => $count) {
            preg_match_all($pattern, $key, $matches);
            foreach ($matches[0] as $tag)
                Utils::array_add($tags, $tag, $count);
        }
    }
    
    public static function parseAndIndex($src) {
        $indexer = new IndexNodeVisitor();
        try {
            ini_set('xdebug.max_nesting_level', 2000);
            $parser = new \PhpParser\Parser(new \PhpParser\Lexer());
            $stmts = $parser->parse($src);
            
            $traverser = new \PhpParser\NodeTraverser();
            $traverser->addVisitor(new \PhpParser\NodeVisitor\NameResolver());
            $traverser->addVisitor($indexer);
            $traverser->traverse($stmts);
        } catch (\PhpParser\Error $e) {
            echo 'Parse Error: ', $e->getMessage() . "\n";
        }
        return $indexer->index;
    }

	public function analyzeProvide($src) {
	    $index = $this->parseAndIndex($src);
        
        $classNames = array();
        $this->tagItems($classNames, $index['provide_class'], '/[^\\\\]+$/');
        $this->tagItems($index['tag'], $classNames);
        
        $this->tagItems($index['tag'], $index['provide_namespace']);
        
        return array(
            'namespace' => $index['provide_namespace'],
            'class' => $index['provide_class'],
            'tag' => $index['tag'],
        );
	}

	public function analyzeUse($src) {
        $index = $this->parseAndIndex($src);
        
        $classNames = array();
        $this->tagItems($classNames, $index['use_class'], '/[^\\\\]+$/');
        $this->tagItems($index['tag'], $classNames);
        
        $this->tagItems($index['tag'], $index['use_namespace']);
        
        return array(
            'namespace' => $index['use_namespace'],
            'class' => $index['use_class'],
            'tag' => $index['tag'],
        );
	}

	public function getName() {
		return 'PHP';
	}

	public function getExtension() {
		return '.php';
	}

}


class IndexNodeVisitor extends \PhpParser\NodeVisitorAbstract
{
    
    public $index;
    
    public function __construct() {
        $this->index = array(
            'tag' => array(),
            'provide_class' => array(),
            'provide_namespace' => array(),
            'use_class' => array(),
            'use_namespace' => array(),
        );
    }
    
    public function enterNode(\PhpParser\Node $node) {
        // Analyze provide
        if ($node instanceof \PhpParser\Node\Stmt\Class_) {
            Utils::array_add($this->index['provide_class'], $node->namespacedName->toString());
        }
        if ($node instanceof \PhpParser\Node\Stmt\Namespace_) {
            Utils::array_add($this->index['provide_namespace'], $node->name->toString());
        }
        
        // Analyze usage
        if ($node instanceof \PhpParser\Node\Stmt\Use_) {
            foreach ($node->uses as $use) {
                Utils::array_add($this->index['use_namespace'], $use->name->toString());
            }
        }
        if ($node instanceof \PhpParser\Node\Expr\New_ && $node->class instanceof \PhpParser\Node\Name\FullyQualified) {
            Utils::array_add($this->index['use_class'], $node->class->toString());
        }
        if ($node instanceof \PhpParser\Node\Param && !empty($node->type) && $node->type != 'array') {
            if (is_string($node->type))
                Utils::array_add($this->index['use_class'], $node->type);
            else
                Utils::array_add($this->index['use_class'], $node->type->toString());
        }
    }
}