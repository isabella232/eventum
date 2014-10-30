<?php

/**
 * Separate test to use different getInstance call
 */
class DbMaxAllowedPacketTest extends PHPUnit_Framework_TestCase {
    public function testPhp56() {
        if (PHP_VERSION_ID >= 50600) {
            $this->markTestSkipped("PEAR::DB not compatible with php 5.6");
        }
    }

    /**
     * @depends testPhp56
     */
    public function testGetMaxAllowedPacket() {
        // this should not fail if db is not reachable
        $res = DB_Helper::getMaxAllowedPacket();
        $this->assertNotNull($res);
        $this->assertGreaterThan(0, $res);
    }
}
