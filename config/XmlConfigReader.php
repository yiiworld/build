<?php
/**
 * XmlConfigReader.php
 * @author Revin Roman http://phptime.ru
 */

namespace cookyii\build\config;

/**
 * Class XmlConfigReader
 * @package cookyii\build\config
 */
class XmlConfigReader extends AbstractConfigReader
{

    /**
     * @return array
     * @throws \Exception
     */
    public function read()
    {
        if (!file_exists($this->configFile)) {
            $this->log('<error>Config file not exists.</error>');

            return false;
        } else {
            $reader = new \Sabre\Xml\Reader();
            $reader->xml(file_get_contents($this->configFile));

            $xml = $reader->parse();

            $config = [];

            return $config;
        }
    }
}