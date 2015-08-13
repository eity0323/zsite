<?php
/**
 * The model file of common module of chanzhiEPS.
 *
 * @copyright   Copyright 2009-2015 青岛易软天创网络科技有限公司(QingDao Nature Easy Soft Network Technology Co,LTD, www.cnezsoft.com)
 * @license     ZPLV1 (http://www.chanzhi.org/license/)
 * @author      Chunsheng Wang <chunsheng@cnezsoft.com>
 * @package     common
 * @version     $Id$
 * @link        http://www.chanzhi.org
 */
class commonModel extends model
{
    /**
     * Do some init functions.
     * 
     * @access public
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->startSession();
        $this->setUser();
        $this->loadConfigFromDB();
        $this->loadAlias();
        $this->loadModel('site')->setSite();
    }

    /**
     * Load configs from database and save it to config->system and config->personal.
     * 
     * @access public
     * @return void
     */
    public function loadConfigFromDB()
    {
        /* Get configs of system and current user. */
        $account = isset($this->app->user->account) ? $this->app->user->account : '';
        if($this->config->db->name) $config  = $this->loadModel('setting')->getSysAndPersonalConfig($account);
        $this->config->system   = isset($config['system']) ? $config['system'] : array();
        $this->config->personal = isset($config[$account]) ? $config[$account] : array();

        /* Overide the items defined in config/config.php and config/my.php. */
        if(isset($this->config->system->common))
        {
            foreach($this->config->system->common as $record)
            {   
                if($record->section)
                {
                    if(!isset($this->config->{$record->section})) $this->config->{$record->section} = new stdclass();
                    if($record->key) $this->config->{$record->section}->{$record->key} = $record->value;
                }
                else
                {
                    if(!$record->section) $this->config->{$record->key} = $record->value;
                }
            }
        }
        $device = helper::getDevice();
        if(isset($this->config->template->{$device}) and !is_object($this->config->template->{$device})) $this->config->template->{$device} = json_decode($this->config->template->{$device});

        if(!isset($this->config->site->status)) $this->config->site->status = 'normal';
        if(($this->loadModel('wechat')->getList())) $this->config->site->wechat = true;
    }

    /**
     * Start the session.
     * 
     * @access public
     * @return void
     */
    public function startSession()
    {
        if(!defined('SESSION_STARTED'))
        {
            $sessionName = $this->config->sessionVar;
            session_name($sessionName);
            session_start();
            define('SESSION_STARTED', true);
        }
    }

    /**
     * Check the priviledge.
     * 
     * @access public
     * @return void
     */
    public function checkPriv()
    {
        $module = $this->app->getModuleName();
        $method = $this->app->getMethodName();

        if($this->isOpenMethod($module, $method)) return true;

        /* If no $app->user yet, go to the login pae. */
        if(RUN_MODE == 'admin' and $this->app->user->account == 'guest')
        {
            $referer = helper::safe64Encode($this->app->getURI(true));
            die(js::locate(helper::createLink('user', 'login', "referer=$referer")));
        }

        /* if remote ip not equal loginIP, go to login page. */
        if(RUN_MODE == 'admin')
        {
            if(zget($this->config->site, 'checkSessionIP', false) and (helper::getRemoteIP() != $this->app->user->loginIP))
            {
                session_destroy();
                $referer = helper::safe64Encode($this->app->getURI(true));
                die(js::locate(helper::createLink('user', 'login', "referer=$referer")));
            }
        }

        /* go to login page, if the setting of front page is need login. */
        if(RUN_MODE == 'front')
        {
            $frontConfig = isset($this->config->site->front) ? $this->config->site->front : 'guest';
            if($frontConfig == 'login' and $this->app->user->account == 'guest')
            {
                $referer = helper::safe64Encode($this->app->getURI(true));
                die(js::locate(helper::createLink('user', 'login', "referer=$referer")));
            }
        }

        /* Check the priviledge. */
        if(!commonModel::hasPriv($module, $method)) $this->deny($module, $method);
        if(!isset($this->config->rights->guest[strtolower($module)][strtolower($method)]) and !helper::isAjaxRequest() and RUN_MODE == 'front' and $this->app->user->account != 'guest' and strtolower($method) != 'checkemail')
        {
            if(isset($this->config->site->checkEmail) and $this->config->site->checkEmail == 'open' and !$this->app->user->emailCertified)
            {
                exit(js::locate(helper::createLink('user', 'checkEmail')));
            }
        }
    }

    /**
     * Check current user has priviledge to the module's method or not.
     * 
     * @param mixed $module     the module
     * @param mixed $method     the method
     * @static
     * @access public
     * @return bool
     */
    public static function hasPriv($module, $method)
    {
        $module = strtolower($module);
        $method = strtolower($method);
        global $app, $config;

        $rights  = $app->user->rights;
        if(RUN_MODE == 'admin')
        {
            if($app->user->admin == 'no') return false;
            if($app->user->admin == 'super') return true;
            if($app->user->admin != 'no' and $module == 'admin' and $method == 'index') return true;
            if(isset($rights[$module][$method])) return true;
            return false;
        }

        if(!commonModel::isAvailable($module)) return false;

        if(isset($rights[$module][$method])) return true;

        /* Check rights one more time to enable new created rights.*/
        if($app->user->account == 'guest')
        { 
            if(isset($config->rights->guest[$module][$method])) return true;
        }
        else
        {
            if(isset($config->rights->guest[$module][$method]) or isset($config->rights->member[$module][$method])) return true;
        }

        return false;
    }

    /**
     * Check whether module is available.
     * 
     * @param  string $module 
     * @static
     * @access public
     * @return void
     */
    public static function isAvailable($module)
    {
        global $app, $config;

        /* Check whether dependence modules is available. */
        if(!empty($config->dependence->$module))
        {
            foreach($config->dependence->$module as $dependModule)
            {
                if(!isset($config->site->modules) or strpos($config->site->modules, $dependModule) === false) return false;
            }
        }

        return true;
    }

    /**
     * Show the deny info.
     * 
     * @param mixed $module     the module
     * @param mixed $method     the method
     * @access public
     * @return void
     */
    public function deny($module, $method)
    {
        if(helper::isAjaxRequest()) exit;
        $vars = "module=$module&method=$method";
        if(isset($_SERVER['HTTP_REFERER']))
        {
            $referer  = helper::safe64Encode($_SERVER['HTTP_REFERER']);
            $vars .= "&referer=$referer";
        }
        $denyLink = helper::createLink('user', 'deny', $vars);
        die(js::locate($denyLink));
    }

    /** 
     * Judge a method of one module is open or not?
     * 
     * @param  string $module 
     * @param  string $method 
     * @access public
     * @return bool
     */
    public function isOpenMethod($module, $method)
    {   
        if($module == 'user' and strpos(',login|logout|deny|resetpassword|checkresetkey', $method)) return true;
        if($module == 'cart' and $method == 'printtopbar') return true;
        if($module == 'mail' and strpos(',captcha|sendmailcode', $method)) return true;
        if($module == 'misc' and strtolower($method) == 'ajaxgetfingerprint') return true;
        if($module == 'wechat' and $method == 'response') return true;
        if(RUN_MODE == 'admin' and $module == 'misc' and $method == 'ping') return true;

        if($this->loadModel('user')->isLogon() and stripos($method, 'ajax') !== false) return true;

        return false;
    }   

    /**
     * Check domain and header 301.
     * 
     * @access public
     * @return void
     */
    public function checkDomain()
    {
        if(RUN_MODE == 'install' or RUN_MODE == 'upgrade' or RUN_MODE == 'shell' or RUN_MODE == 'admin' or !$this->config->installed) return true;

        $httpHost   = $this->server->http_host;
        $currentURI = getWebRoot(true) . $this->app->getURI();
        $scheme     = isset($this->config->site->scheme) ? $this->config->site->scheme : 'http';
        $mainDomain = isset($this->config->site->domain) ? $this->config->site->domain : '';
        $mainDomain = str_replace(array('http://', 'https://'), '', $mainDomain);

        /* Check main domain and scheme. */
        $redirectURI = $currentURI;
        if(strpos($redirectURI, $scheme . '://') !== 0) $redirectURI = $scheme . substr($redirectURI, strpos($redirectURI, '://'));
        if(!empty($mainDomain) and $httpHost != $mainDomain) $redirectURI = str_replace($httpHost, $mainDomain, $redirectURI);
        if($redirectURI != $currentURI) header301($redirectURI);

        /* Check domain is allowed. */
        $allowedDomains = isset($this->config->site->allowedDomain) ? $this->config->site->allowedDomain : '';
        $allowedDomains = str_replace(array('http://', 'https://'), '', $allowedDomains);
        if(!empty($allowedDomains))
        {
            if(strpos($allowedDomains, $httpHost) !== false) return true;
            if(!empty($mainDomain) and hepler::getSiteCode($httpHost) == hepler::getSiteCode($mainDomain)) return true;
            die('domain denied.');
        }
    }

    /**
     * Create the main menu.
     * 
     * @param  string $currentModule 
     * @static
     * @access public
     * @return string
     */
    public static function createMainMenu($currentModule)
    {
        global $app, $lang, $config;

        /* Set current module. */
        if(isset($lang->menuGroups->$currentModule)) $currentModule = $lang->menuGroups->$currentModule;

        $string = "<ul class='nav navbar-nav'>\n";

        /* Print all main menus. */
        foreach($lang->menu as $moduleName => $moduleMenu)
        {
            if($moduleName == 'feedback')
            {
                list($label, $module, $method, $vars) = explode('|', $moduleMenu);

                if(!commonModel::isAvailable('message'))
                {
                    if(commonModel::isAvailable('forum'))
                    {
                        $moduleMenu = "$label|forum|admin|tab=feedback";
                    }
                    elseif(isset($config->site->wechat))
                    {
                        $moduleMenu = "$label|wechat|message|mode=replied&replied=0";
                    }
                    else
                    {
                        continue;
                    }
                }
            }

            $class = $moduleName == $currentModule ? " class='active'" : '';
            list($label, $module, $method, $vars) = explode('|', $moduleMenu);

            if($module != 'user' and $module != 'article' and !commonModel::isAvailable($module)) continue;
            
            /* Just whether article/blog/page menu should shown. */
            if(!commonModel::isAvailable('article') && $vars == 'type=article') continue;  
            if(!commonModel::isAvailable('blog') && $vars == 'type=blog') continue;  
            if(!commonModel::isAvailable('page') && $vars == 'type=page') continue;  

            if(commonModel::hasPriv($module, $method))
            {
                $link  = helper::createLink($module, $method, $vars);
                $string .= "<li$class><a href='$link'>$label</a></li>\n";
            }
        }

        $string .= "</ul>\n";
        return $string;
    }

    /**
     * Create the module menu.
     * 
     * @param  string $currentModule 
     * @static
     * @access public
     * @return void
     */
    public static function createModuleMenu($currentModule, $navClass = 'nav-left nav-primary nav-stacked', $chevron = true)
    {
        global $lang, $app;

        if(!isset($lang->$currentModule->menu)) return false;

        $string = "<ul class='nav " . $navClass . "'>\n";

        /* Get menus of current module and current method. */
        $moduleMenus   = $lang->$currentModule->menu;  
        $currentMethod = $app->getMethodName();

        /* Cycling to print every menus of current module. */
        foreach($moduleMenus as $methodName => $methodMenu)
        {
            if(is_array($methodMenu)) 
            {
                $methodAlias = $methodMenu['alias'];
                $methodLink  = $methodMenu['link'];
            }
            else
            {
                $methodAlias = '';
                $methodLink  = $methodMenu;
            }

            /* Split the methodLink to label, module, method, vars. */
            list($label, $module, $method, $vars) = explode('|', $methodLink);
            if($chevron) $label .= '<i class="icon-chevron-right"></i>';

            if($module != 'user' and $module != 'article' and !commonModel::isAvailable($module)) continue;
            if(commonModel::hasPriv($module, $method))
            {
                $class = '';
                if($module == $currentModule && $method == $currentMethod) $class = " class='active'";
                if($module == $currentModule && strpos($methodAlias, $currentMethod) !== false) $class = " class='active'";
                $string .= "<li{$class}>" . html::a(helper::createLink($module, $method, $vars), $label) . "</li>\n";
            }
        }

        $string .= "</ul>\n";
        return $string;
    }

    /**
     * Create menu for managers.
     * 
     * @access public
     * @return string
     */
    public static function createManagerMenu()
    {
        global $app, $lang , $config;

        $string  = '<ul class="nav navbar-nav navbar-right">';
        $string .= sprintf('<li>%s</li>', html::a($config->homeRoot, '<i class="icon-home icon-large"></i> ' . $lang->frontHome, "target='_blank' class='navbar-link'"));
        $string .= sprintf('<li class="dropdown"><a href="#" class="dropdown-toggle" data-toggle="dropdown"><i class="icon-user icon-large"></i> %s <b class="caret"></b></a>', $app->user->realname);
        $string .= sprintf('<ul class="dropdown-menu"><li>%s</li><li>%s</li><li>%s</li><li>%s</li></ul>', html::a(helper::createLink('user', 'changePassword'), $lang->changePassword, "data-toggle='modal'"), html::a(helper::createLink('misc', 'about'), $lang->about, "data-toggle='modal'"), html::a(helper::createLink('misc','thanks'), $lang->thanks, "data-toggle='modal'"), html::a(helper::createLink('user','logout'), $lang->logout));
        $string .= '</li></ul>';

        return $string;
    }

    /**
     * Print the top bar.
     *
     * @param  boolean $asListItem 
     * @access public
     * @return void
     */
    public static function printTopBar($asListItem = false)
    {
        if(!commonModel::isAvailable('user')) return '';

        global $app;
        if($app->session->user->account != 'guest')
        {
            if($asListItem)
            {
                echo "<li class='menu-user-center text-center'>" . html::a(helper::createLink('user', 'control'), "<div class='user-avatar'><i class='icon icon-user avatar icon-s2 bg-primary circle'></i><strong class='user-name'>{$app->session->user->realname}</strong></div>") . '</li>';
                echo "<li>" . html::a(helper::createLink('user', 'control'), $app->lang->dashboard) . '</li>';
                echo '<li>' . html::a(helper::createLink('user', 'logout'),  $app->lang->logout) . '</li>';
            }
            else
            {
                printf('<span class="login-msg"></span>');
                echo html::a(helper::createLink('user', 'control'), "<i class='icon-user icon-small'> </i>" . $app->session->user->realname);
                echo html::a(helper::createLink('user', 'logout'),  $app->lang->logout);
            }
        }
        else
        {
            if($asListItem)
            {
                echo '<li>' . html::a(helper::createLink('user', 'login'), $app->lang->login) . '</li>';
                echo '<li>' . html::a(helper::createLink('user', 'register'), $app->lang->register) . '</li>';
            }
            else
            {
                echo html::a(helper::createLink('user', 'login'), $app->lang->login);
                echo html::a(helper::createLink('user', 'register'), $app->lang->register);
            }
        }
    }

    /**
     * Print language bar.
     * 
     * @static
     * @param  boolean $asListItem 
     * @access public
     * @return string
     */
    public static function printLanguageBar($asListItem = false)
    {
        global $config, $app;
        $langs = explode(',', $config->site->lang);
        if(count($langs) == 1) return false;
        if($asListItem)
        {
            $clientLang = $app->getClientLang();
            echo "<li class='dropdown-header'>{$app->lang->language}</li>";
            foreach($langs as $lang)
            {
                $a = html::a(getHomeRoot($config->langsShortcuts[$lang]), $config->langs[$lang]);
                $liClass = $clientLang === $lang ? " class='active'" : '';
                $a = "<li{$liClass}>{$a}</li>";
                echo $a;
            }
        }
        else
        {
            foreach($langs as $lang) echo html::a(getHomeRoot($config->langsShortcuts[$lang]), $config->langAbbrLabels[$lang]); 
        }
    }

    /**
     * Print the nav bar.
     * 
     * @static
     * @access public
     * @return void
     */
    public static function printNavBar()
    {
        global $app;
        echo "<ul class='nav'>";
        echo '<li>' . html::a($app->config->homeRoot, $app->lang->homePage) . '</li>';
        foreach($app->site->menuLinks as $menu) echo "<li>$menu</li>";
        echo '</ul>';
    }

    /**
     * Print position bar 
     *
     * @param   object $module 
     * @param   object $object 
     * @param   mixed  $misc    other params. 
     * @access  public
     * @return  void
     */
    public function printPositionBar($module = '', $object = '', $misc = '', $root = '')
    {
        echo '<ul class="breadcrumb">';
        if($root == '')
        {
            echo '<li>' . "<span class='breadcrumb-title'>" . $this->lang->currentPos . $this->lang->colon . '</span>' . html::a($this->config->homeRoot, $this->lang->home) . '</li>';
        }
        else
        {
            echo $root;
        }

        $moduleName = $this->app->getModuleName();
        $moduleName = $moduleName == 'reply' ? 'thread' : $moduleName;
        $funcName = "print$moduleName";
        if(method_exists('commonModel', $funcName)) echo $this->$funcName($module, $object, $misc);
        echo '</ul>';
    }
    
    /**
     * Print the link contains orderBy field.
     *
     * This method will auto set the orderby param according the params. For example, if the order by is desc,
     * will be changed to asc.
     *
     * @param  string $fieldName    the field name to sort by
     * @param  string $orderBy      the order by string
     * @param  string $vars         the vars to be passed
     * @param  string $label        the label of the link
     * @param  string $module       the module name
     * @param  string $method       the method name
     * @static
     * @access public
     * @return void
     */
    public static function printOrderLink($fieldName, $orderBy, $vars, $label, $module = '', $method = '')
    {
        global $lang, $app;
        if(empty($module)) $module = $app->getModuleName();
        if(empty($method)) $method = $app->getMethodName();
        $className = 'header';

        if(strpos($orderBy, $fieldName . '_') !== false)
        {
            if(stripos($orderBy, $fieldName . '_desc') !== false)
            {
                $orderBy   = str_ireplace('desc', 'asc', $orderBy);
                $className = 'headerSortUp';
            }
            elseif(stripos($orderBy, $fieldName . '_asc')  !== false)
            {
                $orderBy = str_ireplace('asc', 'desc', $orderBy);
                $className = 'headerSortDown';
            }
        }
        else
        {
            $orderBy   = $fieldName . '_' . 'asc';
            $className = 'header';
        }

        $link = helper::createLink($module, $method, sprintf($vars, $orderBy));
        echo "<div class='$className'>" . html::a($link, $label) . '</div>';
    }

    /**
     * print link;
     * 
     * @param  string $module 
     * @param  string $method 
     * @param  string $vars 
     * @param  string $label 
     * @param  string $misc 
     * @static
     * @access public
     * @return bool
     */
    public static function printLink($module, $method, $vars = '', $label, $misc = '')
    {   
        if(!commonModel::hasPriv($module, $method)) return false;
        echo html::a(helper::createLink($module, $method, $vars), $label, $misc);
        return true;
    }

    /**
     * Set the user info.
     * 
     * @access public
     * @return void
     */
    public function setUser()
    {
        if($this->session->user) return $this->app->user = $this->session->user;

        /* Create a guest account. */
        $user           = new stdclass();
        $user->id       = 0;
        $user->account  = 'guest';
        $user->realname = 'guest';
        $user->admin    = RUN_MODE == 'cli' ? 'super' : 'no';
        if(RUN_MODE == 'front') $user->rights = $this->config->rights->guest;

        $this->session->set('user', $user);
        $this->app->user = $this->session->user;
    }

    /**
     * Get the run info.
     * 
     * @param mixed $startTime  the start time of this execution
     * @access public
     * @return array    the run info array.
     */
    public function getRunInfo($startTime)
    {
        $info['timeUsed'] = round(getTime() - $startTime, 4) * 1000;
        $info['memory']   = round(memory_get_peak_usage() / 1024, 1);
        $info['querys']   = count(dao::$querys);
        return $info;
    }

    /**
     * Get the full url of the system.
     * 
     * @static
     * @access public
     * @return string
     */
    public static function getSysURL()
    {
        global $config;
        $httpType = isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] == 'on' ? 'https' : 'http';
        $httpHost = $_SERVER['HTTP_HOST'];
        return "$httpType://$httpHost";
    }

    /**
     * Get client IP.
     * 
     * @access public
     * @return void
     */
    public function getIP()
    {
        if(getenv("HTTP_CLIENT_IP"))
        {
            $ip = getenv("HTTP_CLIENT_IP");
        }
        elseif(getenv("HTTP_X_FORWARDED_FOR"))
        {
            $ip = getenv("HTTP_X_FORWARDED_FOR");
        }
        elseif(getenv("REMOTE_ADDR"))
        {
            $ip = getenv("REMOTE_ADDR");
        }
        else
        {
            $ip = "Unknow";
        }

        return $ip;
    }

    /**
     * Print the positon bar of product module.
     * 
     * @param  object $module 
     * @param  object $product 
     * @access public
     * @return void
     */
    public function printProduct($module, $product)
    {
        if(empty($module->pathNames))
        {
            echo '<li>' . $module->name . '</li>';
            return '';
        }
        foreach($module->pathNames as $moduleID => $moduleName)
        {
            echo '<li>' . html::a(inlink('browse', "moduleID=$moduleID", "category=" . $this->loadModel('tree')->getAliasByID($moduleID)), $moduleName) . '</li>';
        }
        if($product) echo '<li>' . $product->name . '</li>';
    }

    /**
     * Print the positon bar of company module.
     * 
     * @param  object $module 
     * @access public
     * @return void
     */
    public function printcompany($module)
    {
        echo '<li>' . $this->lang->aboutUs . '</li>'; 
    }

    /**
     * Print the positon bar of links module.
     * 
     * @param  object $module 
     * @access public
     * @return void
     */
    public function printlinks($module)
    {
        echo '<li>' . $this->lang->link . '</li>'; 
    }

    /**
     * Print the positon bar of article module.
     * 
     * @param  object $module 
     * @param  object $article 
     * @access public
     * @return void
     */
    public function printArticle($module, $article)
    {
        if(empty($module->pathNames)) return '';

        $divider = $this->lang->divider;
        foreach($module->pathNames as $moduleID => $moduleName)
        {
            echo '<li>' . html::a(inlink('browse', "moduleID=$moduleID", "category=" . $this->loadModel('tree')->getAliasByID($moduleID)), $moduleName) . '</li>';
        }
        if($article) echo '<li>' . $article->title . '</li>';
    }

    /**
     * Print the positon bar of blog module.
     * 
     * @param  object $module 
     * @param  object $article 
     * @access public
     * @return void
     */
    public function printBlog($module, $article)
    {
        if(empty($module->pathNames)) return '';

        $divider = $this->lang->divider;
        foreach($module->pathNames as $moduleID => $moduleName)
        {   
            echo '<li>' . html::a(inlink('index', "moduleID=$moduleID", "category=" . $this->config->seo->alias->blog[$moduleID]), $moduleName) . '</li>';
        }
        if($article) echo '<li>' . $article->title . '</li>';
    }

    /**
     * Print the position bar of book module.
     * 
     * @param   array   $families
     * @access  public
     * @return  void
     */
    public function printBook($origins)
    {
        $link = '<li>' . html::a(helper::createLink('book', 'index'), $this->lang->bookHome) . '</li>';
        $book = current($origins);
        foreach($origins as $node)
        {
            if($node->type == 'book') $link .= '<li>' . html::a(helper::createLink('book', 'browse', "bookID=$node->id", "book=$book->alias"), $node->title) . '</li>';
            if($node->type != 'book') $link .= '<li>' . html::a(helper::createLink('book', 'browse', "nodeID=$node->id", "book=$book->alias&node=$node->alias"), $node->title) . '</li>';
        }
        echo $link;
    }

    /**
     * Print the position bar of forum module.
     * 
     * @param   object $board 
     * @access  public
     * @return  void
     */
    public function printForum($board = '')
    {
        if($board == 'forum') echo '<li>' . html::a(helper::createLink('forum', 'index'), $this->lang->forumHome) . '</li>';

        if(empty($board->pathNames)) return '';

        $divider = $this->lang->divider;
        echo '<li>' . html::a(helper::createLink('forum', 'index'), $this->lang->forumHome) . '</li>';
        if(!$board) return false;

        unset($board->pathNames[key($board->pathNames)]);
        foreach($board->pathNames as $boardID => $boardName)
        {
            echo '<li>' . html::a(helper::createLink('forum', 'board', "boardID={$boardID}", "category=" . $this->config->seo->alias->forum[$boardID]), $boardName) . '</li>';
        }
    }

    /**
     * Print the position bar of thread module.
     * 
     * @param   object $board 
     * @param   object $thread 
     * @access  public
     * @return  void
     */
    public function printThread($board, $thread = '')
    {
        $this->printForum($board);
        if($thread) echo '<li>' . $thread->title . '</li>';
    }

    /**
     * Print the positon bar of page module.
     * 
     * @param  object $page 
     * @access public
     * @return void
     */
    public function printPage($page)
    {
        $divider = $this->lang->divider;
        if(!$page) echo '<li>' . $this->lang->page->list . '</li>';
        if($page) echo '<li>' . $page->title . '</li>';
    }

    /**
     * Print the position bar of message module.
     * 
     * @access public
     * @return void
     */
    public function printMessage()
    {
        echo '<li>' . $this->lang->message->common . '</li>';
    }

    /**
     * Print the position bar of Search. 
     * 
     * @param  int    $module 
     * @param  int    $object 
     * @param  int    $keywords 
     * @access public
     * @return void
     */
    public function printSearch($module, $object, $keywords)
    {
        echo "<li> {$this->lang->search->common} </li>" . "<li>{$keywords}</li>";
    }

    /**
     * Create front link for admin MODEL.
     *
     * @param string       $module
     * @param string       $method
     * @param string|array $vars
     * @param string|array $alias
     * return string 
     */
    public static function createFrontLink($module, $method, $vars = '', $alias = '')
    {
        if(RUN_MODE == 'front') return helper::createLink($module, $method, $vars, $alias);

        global $config;

        $requestType = $config->requestType;
        $config->requestType = $config->frontRequestType;
        $link = helper::createLink($module, $method, $vars, $alias);
        $link = str_replace($_SERVER['SCRIPT_NAME'], $config->webRoot . 'index.php', $link);
        $config->requestType = $requestType;

        return $link;
    }
 
    /**
     * Verfy administrator through ok file.
     * 
     * @access public
     * @return array
     */
    public function verfyAdmin()
    {
        if($this->session->okFileName == false or $this->session->okFileName == '')
        {
            $this->session->set('okFileName', helper::createRandomStr(4, $skip = '0-9A-Z') . '.txt');
        }
        $okFile = $this->app->getTmpRoot() . $this->session->okFileName;

        if(file_exists($okFile) and time() - filemtime($okFile) > 3600)
        {
            @unlink($okFile);
            $this->session->set('okFileName', helper::createRandomStr(4, $skip = '0-9A-Z') . '.txt');
            $okFile = $this->app->getTmpRoot() . $this->session->okFileName;
        }

        if(!file_exists($okFile) or time() - filemtime($okFile) > 3600)
        {
            return array('result' => 'fail', 'okFile' => $okFile);
        }

        return array('result' => 'success');
    }
   
    /**
     * Load category and page alias. 
     *
     * @access public
     * @return void
     */
    public function loadAlias()
    {
        if(version_compare($this->loadModel('setting')->getVersion(), 1.4) <= 0) return true;

        $this->config->seo->alias->category = $this->dao->select('alias, id as category, type as module')->from(TABLE_CATEGORY)->where('alias')->ne('')->andWhere('type')->in('article,product')->fetchAll('alias');
        $this->config->seo->alias->page     = $this->dao->select("alias, id, 'page' as module")->from(TABLE_ARTICLE)->where('type')->eq('page')->fetchAll('alias');
        $this->config->seo->alias->forum    = $this->dao->select("id, alias")->from(TABLE_CATEGORY)->where('type')->eq('forum')->fetchPairs('id');
        $this->config->seo->alias->blog     = $this->dao->select("id, alias")->from(TABLE_CATEGORY)->where('type')->eq('blog')->fetchPairs('id');

        $this->config->categoryAlias = array();
        foreach($this->config->seo->alias->category as $alias => $category) $this->config->categoryAlias[$category->category] = $alias;
    }
}
