<?php
namespace GDO\Session;

use GDO\Core\Application;
use GDO\Core\GDO;
use GDO\DB\GDT_AutoInc;
use GDO\DB\GDT_EditedAt;
use GDO\DB\GDT_Object;
use GDO\Net\GDT_IP;
use GDO\Core\GDT_Serialize;
use GDO\DB\GDT_Token;
use GDO\User\GDO_User;
use GDO\Util\Math;
use GDO\Core\Logger;
use GDO\Net\GDT_Url;
use GDO\Date\Time;

/**
 * GDO Database Session handler.
 * @author gizmore
 * @version 6.10
 * @since 3.00
 */
class GDO_Session extends GDO
{
	const DUMMY_COOKIE_CONTENT = 'GDO_like_16_byte';
	
	public static $INSTANCE;
	public static $STARTED = false;
	
	private static $COOKIE_NAME = 'GDO6';
	private static $COOKIE_DOMAIN = 'localhost';
	private static $COOKIE_JS = true;
	private static $COOKIE_HTTPS = true;
	private static $COOKIE_SECONDS = 72600;
	
	###########
	### GDO ###
	###########
// 	public function gdoCached() { return false; }
	public function gdoEngine() { return self::MYISAM; }
	public function gdoColumns()
	{
		return array(
			GDT_AutoInc::make('sess_id'),
			GDT_Token::make('sess_token')->notNull(),
			GDT_Object::make('sess_user')->table(GDO_User::table()),
			GDT_IP::make('sess_ip'),
			GDT_EditedAt::make('sess_time'),
			GDT_Url::make('sess_last_url'),
			GDT_Serialize::make('sess_data'),
		);
	}
	public function getID() { return $this->getVar('sess_id'); }
	public function getToken() { return $this->getVar('sess_token'); }
	public function getUser() { return $this->getValue('sess_user'); }
	public function getIP() { return $this->getValue('sess_ip'); }
	public function getTime() { return $this->getValue('sess_time'); }
	public function getData() { return $this->getValue('sess_data'); }
	public function getLastURL() { return $this->getVar('sess_last_url'); }
	
	/**
	 * Get current user or ghost.
	 * @return GDO_User
	 */
	public static function user()
	{
		if ( (!($session = self::instance())) ||
			(!($user = $session->getUser())) )
		{
			return GDO_User::ghost();
		}
		return $user;
	}
	
	/**
	 * @return self
	 */
	public static function instance()
	{
		if ( (!self::$INSTANCE) && (!self::$STARTED) )
		{
			self::$INSTANCE = self::start();
			self::$STARTED = true; # only one try
		}
		return self::$INSTANCE;
	}
	
	public static function reset()
	{
		self::$INSTANCE = null;
		self::$STARTED = false;
	}
	
	public static function init($cookieName='GDO6', $domain='localhost', $seconds=-1, $httpOnly=true, $https = false)
	{
		self::$COOKIE_NAME = $cookieName;
		self::$COOKIE_DOMAIN = $domain;
		self::$COOKIE_SECONDS = Math::clamp($seconds, -1, 1234567);
		self::$COOKIE_JS = !$httpOnly;
		self::$COOKIE_HTTPS = $https;
	}
	
	######################
	### Get/Set/Remove ###
	######################
	public static function get($key, $initial=null)
	{
		$session = self::instance();
		$data = $session ? $session->getData() : [];
		return isset($data[$key]) ? $data[$key] : $initial;
	}
	
	public static function set($key, $value)
	{
		if ($session = self::instance())
		{
			$data = $session->getData();
			$data[$key] = $value;
			$session->setValue('sess_data', $data);
		}
	}
	
	public static function remove($key)
	{
		if ($session = self::instance())
		{
			$data = $session->getData();
			unset($data[$key]);
			$session->setValue('sess_data', $data);
		}
	}
	
	public static function commit()
	{
		if (self::$INSTANCE)
		{
			self::$INSTANCE->save();
		}
	}
	
	public static function getCookieValue()
	{
		return isset($_COOKIE[self::$COOKIE_NAME]) ? (string)$_COOKIE[self::$COOKIE_NAME] : null;
	}
	
	/**
	 * Start and get user session
	 * @param string $cookieval
	 * @param string $cookieip
	 * @return self
	 */
	private static function start($cookieValue=true, $cookieIP=true)
	{
		# Parse cookie value
		if ($cookieValue === true)
		{
			if (!isset($_COOKIE[self::$COOKIE_NAME]))
			{
				self::setDummyCookie();
				return false;
			}
			$cookieValue = (string)$_COOKIE[self::$COOKIE_NAME];
		}
		
		# Special first cookie
		if ($cookieValue === self::DUMMY_COOKIE_CONTENT)
		{
			$session = self::createSession($cookieIP);
		}
		# Try to reload
		elseif ($session = self::reloadCookie($cookieValue, $cookieIP))
		{
		}
		# Set special first dummy cookie
		else
		{
			self::setDummyCookie();
			return false;
		}
		
		return $session;
	}
	
	public static function reloadID($id)
	{
		self::$INSTANCE = self::getById($id);
	}
	
	public static function reloadCookie($cookieValue)
	{
		if (!strpos($cookieValue, '-'))
		{
			Logger::logError("Invalid Sess Cookie!");
			return false;
		}
		list($sessId, $sessToken) = @explode('-', $cookieValue, 2);
		# Fetch from possibly from cache via find :)
		if (!($session = self::table()->find($sessId, false)))
		{
			Logger::logError("Invalid SessID!");
			return false;
		}
		
		if ($session->getToken() !== $sessToken)
		{
			Logger::logError("Invalid Sess Token!");
			return false;
		}
		
		# IP Check?
		if ( ($ip = $session->getIP()) && ($ip !== GDT_IP::current()) )
		{
			Logger::logError("Invalid Sess IP! $ip != ".GDT_IP::current());
			return false;
		}
		
		self::$INSTANCE = $session;
		GDO_User::setCurrent($session->getUser());
		
		return $session;
	}
	
	public function ipCheck($cookieIP=true)
	{
		return true;
	}
	
	private function setCookie()
	{
		if (!Application::instance()->isCLI())
		{
		    setcookie(self::$COOKIE_NAME, $this->cookieContent(), Application::$TIME + self::$COOKIE_SECONDS, '/', self::$COOKIE_DOMAIN, self::cookieSecure(), !self::$COOKIE_JS);
		}
	}
	
	public function cookieContent()
	{
		return "{$this->getID()}-{$this->getToken()}";
	}
	
	private static function cookieSecure()
	{
		return false; # TODO: Evaluate protocoll and OR with setting.
	}
	
	private static function setDummyCookie()
	{
	    $app = Application::instance();
		if ( (!$app->isCLI()) && (!$app->isUnitTests()) )
		{
		    setcookie(self::$COOKIE_NAME, self::DUMMY_COOKIE_CONTENT, Application::$TIME+300, '/', self::$COOKIE_DOMAIN, self::cookieSecure(), !self::$COOKIE_JS);
		}
	}
	
	private static function createSession()
	{
		$session = self::table()->blank();
		$session->setVar('sess_time', Time::getDate());
		$session->insert();
		$session->setCookie();
		return $session;
	}
}