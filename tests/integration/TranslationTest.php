<?php
/**
 * Bundled translation integration tests.
 *
 * @package OD_WordPress_Monitor
 */

/**
 * Verify that both plugins load their bundled Japanese translations.
 */
final class TranslationTest extends WP_UnitTestCase {
	public function tear_down(): void {
		restore_current_locale();
		unload_textdomain( 'od-wordpress-monitor' );
		unload_textdomain( 'od-monitor-agent' );

		parent::tear_down();
	}

	public function test_translation_loaders_are_registered_before_other_plugin_hooks(): void {
		$this->assertSame( -100, has_action( 'plugins_loaded', 'od_wordpress_monitor_load_textdomain' ) );
		$this->assertSame( -100, has_action( 'plugins_loaded', 'od_monitor_agent_load_textdomain' ) );
	}

	public function test_japanese_locale_uses_bundled_plugin_translations(): void {
		$use_japanese = static fn(): string => 'ja';
		add_filter( 'locale', $use_japanese );
		add_filter( 'determine_locale', $use_japanese );

		try {
			$this->load_bundled_japanese_textdomains();

			$this->assertTrue( is_textdomain_loaded( 'od-wordpress-monitor' ) );
			$this->assertTrue( is_textdomain_loaded( 'od-monitor-agent' ) );
			$this->assertSame( 'サイトを追加', __( 'Add Site', 'od-wordpress-monitor' ) );
			$this->assertSame( 'Agent認証に失敗しました。ユーザー名とアプリケーションパスワードを確認してください。', __( 'Agent authentication failed. Check the username and Application Password.', 'od-wordpress-monitor' ) );
			$this->assertSame( '監視データを閲覧する権限がありません。', __( 'You are not allowed to read monitoring data.', 'od-monitor-agent' ) );
		} finally {
			remove_filter( 'locale', $use_japanese );
			remove_filter( 'determine_locale', $use_japanese );
		}
	}

	public function test_english_locale_falls_back_to_source_strings(): void {
		unload_textdomain( 'od-wordpress-monitor' );
		unload_textdomain( 'od-monitor-agent' );

		$this->assertSame( 'Add Site', __( 'Add Site', 'od-wordpress-monitor' ) );
		$this->assertSame( 'You are not allowed to read monitoring data.', __( 'You are not allowed to read monitoring data.', 'od-monitor-agent' ) );
	}

	private function load_bundled_japanese_textdomains(): void {
		$repository_root = dirname( __DIR__, 2 );

		load_textdomain( 'od-wordpress-monitor', $repository_root . '/plugins/monitor/languages/od-wordpress-monitor-ja.mo' );
		load_textdomain( 'od-monitor-agent', $repository_root . '/plugins/agent/languages/od-monitor-agent-ja.mo' );
	}
}
