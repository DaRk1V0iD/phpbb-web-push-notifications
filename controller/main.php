<?php
/**
 *
 * @package       Push Notifications
 * @copyright (c) 2017 - 2018 LavIgor
 * @license       http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 *
 */

namespace lavigor\notifications\controller;

use lavigor\notifications\functions\subscription;
use Symfony\Component\HttpFoundation\JsonResponse;

class main
{
	/** @var \phpbb\user */
	protected $user;

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\controller\helper */
	protected $helper;

	/** @var \phpbb\request\request_interface */
	protected $request;

	/** @var string */
	protected $php_ext;

	/** @var string */
	protected $phpbb_root_path;

	/** @var string */
	protected $subscriptions_table;

	public function __construct($user, $db, $config, $helper, $request, $php_ext, $phpbb_root_path, $subscriptions_table)
	{
		$this->user = $user;
		$this->db = $db;
		$this->config = $config;
		$this->helper = $helper;
		$this->request = $request;
		$this->php_ext = $php_ext;
		$this->phpbb_root_path = $phpbb_root_path;
		$this->subscriptions_table = $subscriptions_table;
	}

	/**
	 * Saves current browser's subscription to board's database
	 *
	 * @return JsonResponse
	 */
	public function subscribe()
	{
		if (!$this->request->is_ajax() || $this->user->data['user_id'] == ANONYMOUS)
		{
			redirect(append_sid("{$this->phpbb_root_path}index.{$this->php_ext}"));
		}

		if ($this->check_browsers_limit_reached())
		{
			return new JsonResponse([
				'status' => 'error',
				'error_max_limit'  => $this->user->lang('BROWSER_NOTIFICATIONS_MAX_LIMIT_REACHED', $this->config['push_max_browsers']),
			]);
		}

		$endpoint = $this->request->variable('endpoint', '', true);
		$keys = $this->request->variable('keys', array('' => ''), true);

		$subscription = new subscription($this->user, $this->db, $this->subscriptions_table);

		$this->remove_old_subscription($subscription);

		$subscription
			->set_endpoint($endpoint)
			->set_keys($keys)
			->submit();

		return new JsonResponse([
			'status' => 'success',
			'id'     => $subscription->get_id(),
		]);
	}

	/**
	 * Removes current browser's subscription from board's database
	 *
	 * @return JsonResponse
	 */
	public function unsubscribe()
	{
		if (!$this->request->is_ajax() || $this->user->data['user_id'] == ANONYMOUS)
		{
			redirect(append_sid("{$this->phpbb_root_path}index.{$this->php_ext}"));
		}
		$endpoint = $this->request->variable('endpoint', '', true);
		$keys = $this->request->variable('keys', array('' => ''), true);

		$subscription = new subscription($this->user, $this->db, $this->subscriptions_table);
		$subscription
			->set_endpoint($endpoint)
			->set_keys($keys)
			->remove();

		return new JsonResponse([
			'status' => 'success',
		]);
	}

	/**
	 * Removes current user's all subscription(s) from board's database
	 *
	 * @return JsonResponse
	 */
	public function unsubscribe_all()
	{
		if (!$this->request->is_ajax() || $this->user->data['user_id'] == ANONYMOUS)
		{
			redirect(append_sid("{$this->phpbb_root_path}index.{$this->php_ext}"));
		}

		$sql = 'DELETE FROM ' . $this->subscriptions_table . ' WHERE user_id = ' . (int) $this->user->data['user_id'];
		$this->db->sql_query($sql);

		return new JsonResponse([
			'status' => 'success',
		]);
	}

	/**
	 * Remove old subscription with the same ID as requested
	 * Used in case of re-subscription from Service Worker
	 *
	 * @param subscription $subscription Subscription object
	 */
	protected function remove_old_subscription($subscription)
	{
		$old_id = $this->request->variable('subscription_id', 0);
		if ($old_id)
		{
			$subscription->remove_by_id($old_id);
		}
	}

	/**
	 * Checks whether the current subscription attempt does not
	 * make the allowed limit of number of browsers exceeded
	 *
	 * @return bool
	 */
	protected function check_browsers_limit_reached()
	{
		if ($this->config['push_max_browsers'] < 1)
		{
			return false;
		}

		$sql = $this->db->sql_build_query('SELECT', [
			'SELECT' => 'COUNT(*) as result',
			'FROM'   => [
				$this->subscriptions_table => 's',
			],
			'WHERE'  => 'user_id = ' . (int) $this->user->data['user_id'],
		]);
		$res = $this->db->sql_query($sql);
		$browsers_number = $this->db->sql_fetchfield('result', 0, $res);
		$this->db->sql_freeresult($res);

		return $browsers_number >= $this->config['push_max_browsers'];
	}
}
