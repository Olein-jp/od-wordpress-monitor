<?php
/**
 * アンインストール処理。
 *
 * 誤操作によるデータ消失を防ぐため、監視データを意図的に保持する。
 *
 * @package OD_WordPress_Monitor
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/*
 * このno-opは未実装のcleanupではなく、アンインストール方針そのものです。
 *
 * 登録サイト、暗号化済みcredential、監視履歴、現在状態、イベント、optionを
 * 再インストールや復元に利用できる状態で保持します。将来、破壊的なcleanupを
 * 追加する場合も、個別に明示承認された処理とし、ここでは既定で有効にしません。
 */
