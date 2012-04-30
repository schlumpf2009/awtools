<?PHP

/**
 * AwSession
 *
 * Session Management
 *
 * @package awtools
 * @author Florian Knodt <adlerweb@adlerweb.info>
 * @see AwSQL
 *
 * @env AW_SESSION_NOAUTO bool if defined auto-instantiate is turned off
 */

if(!session_id()) session_start();

class adlerweb_session {
    /**
     * @var int Counter for failed login tries
     */
    protected $retry_count=0;
    
    /**
     * @var int Timestamp when user was blocked due to too many login tries
     */
    protected $retry_time=0;
    
    /**
     * @category config
     * @var int Number of seconds to block a user if he reached our retry-count
     */
    protected $retry_timeout = 300;
    
    /**
     * @category config
     * @var int How many retries before we block an user
     */
    protected $retry_allowed = 3;
    
    /**
     * @var bool|string False or human-readable description of last error
     */
    public $lasterror='';
    
    /**
     * Inizialize system
     */
    public function __construct() {
        //Check for working SQL
        if(!isset($GLOBALS['adlerweb']['sql'])) {
            trigger_error('No SQL-socket in [adlerweb][sql] - Please load AwSQL first...', E_USER_ERROR);
            return false;
        }

        $this->smarty_repopulate();
        $this->retry_housekeeping();
    }
    
    /**
     * Try to login
     * On success $_SESSION['adlerweb']['session'] is populated with user's details
     * @var string $user Username
     * @var string $pass Password
     * @return bool
     */
    public function session_login($user, $pass) {
        $user=strtolower($user);
        if(!$this->session_validUser($user)) {  
            $this->retry++;
            $this->lasterror='Incorrect username format';
            return false;
        }
        if($this->retry_time >= time()) {
            $this->lasterror='Too many incorrect tries';
            return false;
        }
        $check=$GLOBALS['adlerweb']['sql']->query("SELECT UserID,Password,Level,UIdent,Name FROM Users WHERE Nickname='".$user."' LIMIT 1;");
        if($check->num_rows != 1) {
            $this->retry++;
            $this->lasterror='User not found';
            return false;
        }
        $check=$check->fetch_object();
        if(!$this->session_comparePassword( $pass, $check->Password )) {
            $this->retry++;
            $this->lasterror='Incorrect password';
            return false;
        }
        $_SESSION['adlerweb']['session']['level'] = $check->Level;
        $_SESSION['adlerweb']['session']['short'] = $check->UIdent;
        $_SESSION['adlerweb']['session']['user']  = $check->Name;
        $_SESSION['adlerweb']['session']['UID']   = $check->UserID;
        return true;
    }
    
    /**
     * Check if the current user is logged in
     * @return bool|int false or integer of user's permission level
     */
    public function session_isloggedin() {
        if(!isset($_SESSION['adlerweb']['session']['level']) || $_SESSION['adlerweb']['session']['level']<=0) return false;
        return $_SESSION['adlerweb']['session']['level'];
    }
    
    /**
     * Close a session
     */
    public function session_logout() {
        if($this->session_isloggedin() !== false) {
            session_destroy();
            session_start();
        }
    }
    
    /**
     * Modified password salt generator
     * @author original by richardlord.net
     * @return string (Pseudo)Random string intended as salt
     */
    private function session_getPasswordSalt() {
        return substr(str_pad(dechex(mt_rand()), 8, '0', STR_PAD_LEFT), -8);
    }
    
    /**
     * Modified password generator
     * @author original by richardlord.net
     * @var string $salt as generated by session_getPasswordSalt
     * @var string unsalted Password
     * @return string salted password
     */
    private function session_getPasswordHash($salt, $password) {
        return $salt.hash('sha256', hash('whirlpool', $salt.$password));
    }
    
    /**
     * Compare a plaintext password with its salted version
     * @author original by richardlord.net
     * @var string $password cleartext
     * @var string $hash salted version
     * @return bool
     */
    private function session_comparePassword($password, $hash) {
        $salt = substr($hash, 0, 8);
        return $hash == $this->session_getPasswordHash($salt, $password);
    }
    
    /**
     * Limit valid usernames to a specific regex (a-z, 0-9, - and _)
     * @var string $user Username
     * @return bool
     */
    private function session_validUser($user) {
        return preg_match("/^[a-z0-9\-_]+$/", $user);
    }
    
    /**
     * Repopulate smarty's session-vars if AwSmarty is used
     */
    private function smarty_repopulate() {
        if(isset($GLOBALS['adlerweb']['tpl'])) {
            if(isset($_SESSION['adlerweb']['session']['level']) && isset($_SESSION['adlerweb']['session']['short']) && isset($_SESSION['adlerweb']['session']['user'])) {
                $GLOBALS['adlerweb']['tpl']->assign('loginlevel', $_SESSION['adlerweb']['session']['level']);
                $GLOBALS['adlerweb']['tpl']->assign('user', $_SESSION['adlerweb']['session']['user']);
                $GLOBALS['adlerweb']['tpl']->assign('short', $_SESSION['adlerweb']['session']['short']); //DiBAS-Compatibility
                $GLOBALS['adlerweb']['tpl']->assign('UID', $_SESSION['adlerweb']['session']['UID']);
            }else{
                $GLOBALS['adlerweb']['tpl']->assign('loginlevel', 0);
            }
        }
    }
    
    /**
     * Check for expired retry count
     */
    private function retry_housekeeping() {
        if($this->retry_count > $this->retry_allowed){
            $_SESSION['adlerweb']['session']['retrytime'] = time()+$this->retry_timeout;
            $this->retry_cont=0;
        }elseif($this->retry_time > 0 && $this->retry_time <= time()) {
            $this->retry_time = 0;
        }
    }
}

/**
 * Instantiate new global AwSession-object if not deactivated
 */
if(!defined('AW_SESSION_NOAUTO')) {
    $GLOBALS['adlerweb']['session'] = new adlerweb_session();
}

?>
