<?php

use MediaWiki\Site\DBSiteStore;
use MediaWiki\Site\MediaWikiSite;
use MediaWiki\Site\Site;
use MediaWiki\Site\SiteList;

/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @since 1.21
 *
 * @ingroup Site
 * @ingroup Test
 *
 * @group Site
 * @group Database
 *
 * @author Jeroen De Dauw < jeroendedauw@gmail.com >
 */
class DBSiteStoreTest extends MediaWikiIntegrationTestCase {

	/**
	 * @return DBSiteStore
	 */
	private function newDBSiteStore() {
		return new DBSiteStore( $this->getServiceContainer()->getConnectionProvider() );
	}

	/**
	 * @covers \MediaWiki\Site\DBSiteStore::getSites
	 */
	public function testGetSites() {
		$expectedSites = TestSites::getSites();
		TestSites::insertIntoDb();

		$store = $this->newDBSiteStore();

		$sites = $store->getSites();

		$this->assertInstanceOf( SiteList::class, $sites );

		/**
		 * @var Site $site
		 */
		foreach ( $sites as $site ) {
			$this->assertInstanceOf( Site::class, $site );
		}

		foreach ( $expectedSites as $site ) {
			if ( $site->getGlobalId() !== null ) {
				$this->assertTrue( $sites->hasSite( $site->getGlobalId() ) );
			}
		}
	}

	/**
	 * @covers \MediaWiki\Site\DBSiteStore::saveSites
	 */
	public function testSaveSites() {
		$store = $this->newDBSiteStore();

		$sites = [];

		$site = new Site();
		$site->setGlobalId( 'ertrywuutr' );
		$site->setLanguageCode( 'en' );
		$sites[] = $site;

		$site = new MediaWikiSite();
		$site->setGlobalId( 'sdfhxujgkfpth' );
		$site->setLanguageCode( 'nl' );
		$sites[] = $site;

		$this->assertTrue( $store->saveSites( $sites ) );

		$site = $store->getSite( 'ertrywuutr' );
		$this->assertInstanceOf( Site::class, $site );
		$this->assertEquals( 'en', $site->getLanguageCode() );
		$this->assertIsInt( $site->getInternalId() );
		$this->assertGreaterThanOrEqual( 0, $site->getInternalId() );

		$site = $store->getSite( 'sdfhxujgkfpth' );
		$this->assertInstanceOf( Site::class, $site );
		$this->assertEquals( 'nl', $site->getLanguageCode() );
		$this->assertIsInt( $site->getInternalId() );
		$this->assertGreaterThanOrEqual( 0, $site->getInternalId() );
	}

	/**
	 * @covers \MediaWiki\Site\DBSiteStore::reset
	 */
	public function testReset() {
		TestSites::insertIntoDb();
		$store1 = $this->newDBSiteStore();
		$store2 = $this->newDBSiteStore();

		// initialize internal cache
		$this->assertGreaterThan( 0, $store1->getSites()->count() );
		$this->assertGreaterThan( 0, $store2->getSites()->count() );

		// Clear actual data. Will purge the external cache and reset the internal
		// cache in $store1, but not the internal cache in store2.
		$store1->clear();

		// check: $store2 should have a stale cache now
		$this->assertNotNull( $store2->getSite( 'enwiki' ) );

		// purge cache
		$store2->reset();

		// ...now the internal cache of $store2 should be updated and thus empty.
		$site = $store2->getSite( 'enwiki' );
		$this->assertNull( $site );
	}

	/**
	 * @covers \MediaWiki\Site\DBSiteStore::clear
	 */
	public function testClear() {
		$store = $this->newDBSiteStore();
		$store->clear();

		$site = $store->getSite( 'enwiki' );
		$this->assertNull( $site );

		$sites = $store->getSites();
		$this->assertSame( 0, $sites->count() );
	}

	/**
	 * @covers \MediaWiki\Site\DBSiteStore::getSites
	 */
	public function testGetSitesDefaultOrder() {
		$store = $this->newDBSiteStore();
		$siteB = new Site();
		$siteB->setGlobalId( 'B' );
		$siteA = new Site();
		$siteA->setGlobalId( 'A' );
		$store->saveSites( [ $siteB, $siteA ] );

		$sites = $store->getSites();
		$siteIdentifiers = [];
		/** @var Site $site */
		foreach ( $sites as $site ) {
			$siteIdentifiers[] = $site->getGlobalId();
		}
		$this->assertSame( [ 'A', 'B' ], $siteIdentifiers );

		// Note: SiteList::getGlobalIdentifiers uses an other internal state. Iteration must be
		// tested separately.
		$this->assertSame( [ 'A', 'B' ], $sites->getGlobalIdentifiers() );
	}
}
