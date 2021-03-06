<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @package Piwik
 */

namespace Hooks;

class MyHookVisitor extends \PHPParser_NodeVisitorAbstract
{
    private $events     = array();
    private $classes    = array();
    private $namespaces = array();
    private $piwikFile  = '';

    public function __construct($piwikFile)
    {
        $this->piwikFile = $piwikFile;
    }

    private function getCurrentClass()
    {
        if (empty($this->classes)) {
            return '';
        }

        $len = count($this->classes);

        return $this->classes[$len - 1];
    }

    private function getCurrentNamespace()
    {
        if (empty($this->namespaces)) {
            return '';
        }

        $len = count($this->namespaces);

        return $this->namespaces[$len - 1];
    }

    public function enterNode(\PHPParser_Node $node)
    {
        if ($node instanceof \PHPParser_Node_Stmt_Namespace) {

            if (!empty($node->name->parts[0])) {
                $this->namespaces[] = implode('/', $node->name->parts);
            }

        } elseif ($node instanceof \PHPParser_Node_Stmt_Class) {

            $this->classes[] = $node->name;

        }
    }

    public function leaveNode(\PHPParser_Node $node) {

        if ($node instanceof \PHPParser_Node_Expr_StaticCall) {
            if (!$node->name || 'postEvent' !== $node->name) {
                return;
            }

            if (empty($node->class->parts) || !in_array('Piwik', $node->class->parts)) {
                return;
            }

            $event = array(
                'name'      => '',
                'category'  => '',
                'arguments' => array(),
                'comment'   => null,
                'file'      => $this->piwikFile,
                'line'      => $node->getLine(),
                'class'     => $this->getCurrentClass(),
                'namespace' => $this->getCurrentNamespace(),
            );

            $args = $node->args;
            if (!empty($args)) {
                $eventArg = array_shift($args);

                $event['name']     = $this->getEventName($eventArg, $event);
                $event['category'] = $this->getCategoryFromEventName($event['name']);
            }

            if (!empty($args)) {
                $event['arguments'] = $this->getArg(array_shift($args));
            }

            $docComment = $this->getDocComment($node);
            if (!empty($docComment)) {
                $event['comment'] = $docComment;
            } else {
                echo sprintf("Hook %s has no documentation\n", $event['name']);
            }

            if (!empty($event['comment']['ignore'])) {
                return;
            }

            $this->events[] = $event;
        }
    }

    public function afterTraverse(array $nodes)
    {
        return $this->events;
    }

    public function getArg(\PHPParser_Node_Arg $arg)
    {
        if ($arg->value instanceof \PHPParser_Node_Expr_ClassConstFetch) {

            $constant  = $arg->value;
            $rightPart = $constant->name;

            if (array_key_exists($rightPart, MyConstantVisitor::$constants)) {
                return MyConstantVisitor::$constants[$rightPart];
            }
        }

        $prettyPrinter = new \PHPParser_PrettyPrinter_Default();

        return $prettyPrinter->prettyPrintExpr($arg->value);
    }

    private function getDocComment(\PHPParser_Node $node)
    {
        $docComment = $node->getDocComment();
        if (empty($docComment)) {
            return;
        }

        $docParser = new \Sami\Parser\DocBlockParser();
        $parsedDoc = $docParser->parse($docComment->getText());

        $ignore = $parsedDoc->getTag('ignore');

        return array(
            'raw'       => $docComment->getText(),
            'formatted' => $docComment->getReformattedText(),
            'shortDesc' => trim($parsedDoc->getShortDesc()),
            'longDesc'  => trim($parsedDoc->getLongDesc()),
            'ignore'    => !empty($ignore),
            'params'    => $parsedDoc->getTag('param')
        );
    }

    private function getEventName($eventArg)
    {
        $eventName = str_replace("'", '', $this->getArg($eventArg));

        if (false !== strpos($eventName, 'sprintf')) {
            $eventName = str_replace("sprintf(", '', $eventName);
            $eventName = str_replace(")", '', $eventName);

            $partsOfName = explode(', ', $eventName);
            if (2 <= count($partsOfName)) {
                $eventName = vsprintf(array_shift($partsOfName), $partsOfName);
            }
        }

        return $eventName;
    }

    private function getCategoryFromEventName($eventName)
    {
        $categories = explode('.', $eventName);

        return array_shift($categories);
    }
}