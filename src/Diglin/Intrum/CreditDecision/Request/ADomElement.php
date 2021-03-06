<?php
/**
 * Diglin GmbH - Switzerland
 *
 * @author      Sylvain Rayé <support at diglin.com>
 * @category    Diglin
 * @package     Diglin_Intrum
 * @copyright   Copyright (c) 2011-2015 Diglin (http://www.diglin.com)
 */
namespace Diglin\Intrum\CreditDecision\Request;

/**
 * Class ADomElement
 * @package Diglin\Intrum\Request
 */
class ADomElement extends \DOMElement implements \ArrayAccess
{
    /**
     * @var string
     */
    protected $elementName;

    /**
     * @var array
     */
    private $_data;

    /**
     * @var array
     */
    protected $requiredProperties = array();

    /**
     * @var array
     */
    protected $optionalProperties = array();

    /**
     * @param null|string $name
     * @param null|string $value
     * @param null|string $uri
     */
    public function __construct($name = null, $value = null, $uri = null)
    {
        if (is_null($name)) {
            $name = $this->elementName;
        }
        parent::__construct($name, $value, $uri);
    }

    /**
     * @return array
     */
    public function getRequiredProperties()
    {
        return (array) $this->requiredProperties;
    }

    /**
     * @return array
     */
    public function getOptionalProperties()
    {
        return (array) $this->optionalProperties;
    }

    /**
     * @param array $data
     * @param ADomElement $object
     * @return $this
     */
    public function addData(array $data, ADomElement $object = null)
    {
        if (is_null($object)) {
            $object = $this;
        }

        if (!is_null($data)) {
            foreach ($data as $key => $value) {
                $getValue = null;

                $method = $this->_getGetterMethod($key);
                $dataObject = $object->$method();
                if (!empty($dataObject) || empty($value)) {
                    continue;
                }

                $method = $this->_getSetterMethod($key);
                if (is_callable(array($object, $method))) {
                    $object->$method($value);
                }
            }
        }

        return $this;
    }

    /**
     * @param array $data
     * @param ADomElement $object
     * @return $this
     */
    public function appendDataProperties(array $data = null, ADomElement $object = null)
    {
        if (is_null($object)) {
            $object = $this;
        }

        if (!is_null($data)) {
            $this->addData($data, $object);
        }

        $dataProperties = $object->getDataProperties();
        foreach ($dataProperties as $key => $value) {
            if (is_null($value) || $value == '') {
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $nodeName => $node) {
                    if (!is_numeric($nodeName)) {
                        $elementValue = null;
                        if ($node instanceof ADomElement || !is_array($node)) {
                            $elementValue = $node;
                        }
                        $appendChildNode = $this->appendChild(new ADomElement($nodeName, $elementValue));
                    }

                    if (!isset($appendChildNode)) {
                        $appendChildNode = $this;
                    }

                    if ($node instanceof ADomElement) {
                        /* @var $child ADomElement */
                        $child = $appendChildNode->appendChild($node);
                        $child->appendDataProperties($node->getDataProperties(false));
                    } else if (is_array($node)) {
                        foreach ($node as $itemName => $item) {
                            if ($item instanceof \DOMElement) {
                                $child = $appendChildNode->appendChild($item);
                                $child->appendDataProperties($item->getDataProperties(false));
                            } else {
                                $appendChildNode->appendChild(new ADomElement($itemName, $item));
                            }
                        }
                    }
                    $appendChildNode = null;
                }
            } else if (!$value instanceof \DOMElement) {
                $this->appendChild(new \DOMElement($key, $value));
            } else {
                /* @var $child ADomElement */
                $child = $this->appendChild($value);
                $child->appendDataProperties($value->getDataProperties());
            }
        }

        return $this;
    }

    /**
     * Get all properties of a class as an array to be send or use properly by the API
     *
     * @return array
     */
    public function getDataProperties($keepObject = true)
    {
        $data = array();
        $reflect = new \ReflectionObject($this);

        foreach ($reflect->getProperties(\ReflectionProperty::IS_PROTECTED) as $property) {

            $skipProperties = array('requiredProperties', 'optionalProperties', 'elementName');
            if (in_array($property->getName(), $skipProperties)) {
                continue;
            }

            $method = $this->_getGetterMethod($property->getName());

            $value = null;
            if (is_callable(array($this, $method))) {
                $value = $this->$method();
            }

            if ($value instanceof ADomElement && !$keepObject) {
                $value = $value->getDataProperties($keepObject);
            }

            if (is_array($value)) {
                foreach ($value as $key => $item) {
                    if ($item instanceof ADomElement && !$keepObject) {
                        $value[$key] = $item->getDataProperties($keepObject);
                    }
                }
            }

            // skip empty value for properties which are optional
            if (is_null($value) && in_array(substr($property->getName(), 1, strlen($property->getName())), $this->optionalProperties)) {
                continue;
            }

            $data[$this->_normalizeProperty($property->getName())] = $value;
        }

        return $data;
    }

    /**
     * Normalize the property from "_myProperty" to "MyProperty"
     *
     * @param $name
     * @return string
     */
    protected function _normalizeProperty($name)
    {
        if (strpos($name, '_') === 0) {
            $name = substr($name, 1, strlen($name));
        }

        $result = explode('_', $name);
        foreach ($result as $key => $value) {
            $result[$key] = ucwords($value);
        }

        $name = implode('', $result);

        return ucwords($name);
    }

    /**
     * Get the getter method name
     *
     * @param $name
     * @return string
     */
    protected function _getGetterMethod($name)
    {
        return 'get' . $this->_normalizeProperty($name);
    }

    /**
     * Get the setter method name
     *
     * @param $name
     * @return string
     */
    protected function _getSetterMethod($name)
    {
        return 'set' . $this->_normalizeProperty($name);
    }

    /**
     * Implementation of ArrayAccess::offsetSet()
     *
     * @link http://www.php.net/manual/en/arrayaccess.offsetset.php
     * @param string $offset
     * @param mixed $value
     */
    public function offsetSet($offset, $value)
    {
        $method = $this->_getSetterMethod($offset);
        if (is_callable(array($this, $method))) {
            $this->$method($value);
        } else {
            $this->_data[$offset] = $value;
        }
    }

    /**
     * Implementation of ArrayAccess::offsetExists()
     *
     * @link http://www.php.net/manual/en/arrayaccess.offsetexists.php
     * @param string $offset
     * @return boolean
     */
    public function offsetExists($offset)
    {
        $method = $this->_getGetterMethod($offset);
        if (is_callable(array($this, $method))) {
            return (bool) $this->$method();
        } else {
            return isset($this->_data[$offset]);
        }
    }

    /**
     * Implementation of ArrayAccess::offsetUnset()
     *
     * @link http://www.php.net/manual/en/arrayaccess.offsetunset.php
     * @param string $offset
     */
    public function offsetUnset($offset)
    {
        $method = $this->_getSetterMethod($offset);
        if (is_callable(array($this, $method))) {
            $this->$method(null);
        } else {
            unset($this->_data[$offset]);
        }
    }

    /**
     * Implementation of ArrayAccess::offsetGet()
     *
     * @link http://www.php.net/manual/en/arrayaccess.offsetget.php
     * @param string $offset
     * @return mixed
     */
    public function offsetGet($offset)
    {
        $method = $this->_getGetterMethod($offset);
        if (is_callable(array($this, $method))) {
            return $this->$method();
        } else {
            return isset($this->_data[$offset]) ? $this->_data[$offset] : null;
        }
    }
}
