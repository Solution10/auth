<?php

namespace Solution10\Auth;

use Solution10\Collection\Collection;

/**
 * Authentication Library.
 *
 * @package       Solution10
 * @category      Auth
 * @author        Alex Gisby <alex@solution10.com>
 * @license       MIT
 * @uses          Solution10\Collection\Collection
 */
class Auth
{
    /**
     * @var    string    Instance name
     */
    protected $name;

    /**
     * @var    array    Array of instances
     */
    protected static $instances = array();

    /**
     * @var SessionDelegate Instance of the SessionDelegate interface.
     */
    protected $session;

    /**
     * @var    StorageDelegate    Storage Delegate implementation. DB access basically.
     */
    protected $storage;

    /**
     * @var array Options for this instance.
     */
    protected $options;

    /**
     * @var  mixed    The representation of the user that StorageDelegate passes back
     */
    protected $user;

    /**
     * @var    array     Permissions cache
     */
    protected $permissions_cache = array();

    /**
     * Constructor. Pass in all the options for this instance, including all your
     * hashing and salting stuff.
     *
     * @param   string          $name       Name of this instance.
     * @param   SessionDelegate $session    The SessionDelegate implementation for storing Session type data
     * @param   StorageDelegate $storage    The StorageDelegate implementation for data access.
     * @param   array           $options    Options. Must contain, err, something.
     * @throws
     */
    public function __construct($name, SessionDelegate $session, StorageDelegate $storage, array $options)
    {
        $this->name = $name;
        $this->session = $session;
        $this->storage = $storage;

        // Set some sane defaults
        $defaultOptions = array(
            'cost' => 8,
        );

        $this->options = array_merge($defaultOptions, $options);

        self::$instances[$name] = $this;
    }

    /**
     * Retrieving the name of the instance
     *
     * @return string
     */
    public function name()
    {
        return $this->name;
    }

    /**
     * Fetching an instance by name
     *
     * @param   string      $name   Name of the instance
     * @return  Auth|false
     */
    public static function instance($name)
    {
        return (isset(self::$instances[$name])) ? self::$instances[$name] : false;
    }

    /**
     * Fetching all instances of Auth
     *
     * @return    array
     */
    public static function instances()
    {
        return self::$instances;
    }

    /**
     * Hashing a password
     *
     * @param   string   $pass    Plaintext password to hash
     * @return  string   Hashed representation of password
     */
    public function hashPassword($pass)
    {
        return password_hash($pass, PASSWORD_BCRYPT, array('cost' => $this->options['cost']));
    }

    /**
     * Checks if a password matches the hashed variant
     *
     * @param   string  $pass   Password to check
     * @param   string  $hash   Hash to check against
     * @return  bool
     */
    public function checkPassword($pass, $hash)
    {
        return password_verify($pass, $hash);
    }

    /**
     * Attempt to log a user in. Will ask the PersistentStore to store the fact
     * that a user logged in, and will tell & use StorageDelegate to fetch the data
     * and that it occurred.
     *
     * @param   string  $username    Username field value
     * @param   string  $password    Password
     * @return  bool
     * @uses    StorageDelegate::authFetchUserByUsername
     * @uses    PersistentStore::authWrite
     */
    public function login($username, $password)
    {
        $user = $this->storage->authFetchUserByUsername($this->name(), $username);
        if (!$user) {
            return false;
        }

        if (!$this->checkPassword($password, $user['password'])) {
            return false;
        }

        // Awesome, their details are good, log them in:
        $this->session->authWrite($this->name(), $user['id']);

        return true;
    }

    /**
     * Checking if a user is logged in or not.
     *
     * @return  bool
     * @uses    SessionDelegate::authRead
     */
    public function loggedIn()
    {
        return (bool)$this->session->authRead($this->name());
    }

    /**
     * Logs a user out. Mostly just a call to the PersistentStore to null
     * the session
     *
     * @return  void
     * @uses    SessionDelegate::authDelete
     */
    public function logout()
    {
        $this->session->authDelete($this->name());
        $this->user = false;
    }

    /**
     * Forces a UserRepresentation or user ID to be logged in.
     * Use this with extreme caution, it doesn't do any validation, it'll
     * just blindly let them in. Only use this for post-registration steps
     * if it all.
     *
     * @param   UserRepresentation|int
     * @return  bool    Whether the force worked or not.
     */
    public function forceLogin($user)
    {
        if (is_object($user) && ($user instanceof UserRepresentation) == false) {
            return false;
        }

        $user = (is_object($user)) ?
            $user :
            $this->storage->authFetchUserRepresentation($this->name(), $user);

        if (!$user) {
            return false;
        }

        $this->session->authWrite($this->name(), $user->id());

        return true;
    }

    /**
     * Returns the currently logged in user. False if there's no user.
     *
     * @return  mixed   Whatever the StorageDelegate throws back
     * @uses    StorageDelegate::authFetchUserRepresentation
     */
    public function user()
    {
        if (!$this->loggedIn()) {
            return false;
        }

        if (!isset($this->user)) {
            $this->user = $this->storage->authFetchUserRepresentation(
                $this->name(),
                $this->session->authRead($this->name())
            );
        }

        // If the user is false, we've got a bad-un, so kill the session:
        if (!$this->user) {
            $this->logout();
        }

        return $this->user;
    }

    /**
     * Shortcut for loading user representation, will throw correct exception
     * if the user is not found.
     *
     * @param   mixed   $user_id    User primary key
     * @return  mixed   User rep from authFetchUserRepresentation
     * @throws  Exception\Package
     * @uses    StorageDelegate
     */
    protected function loadUserRepresentation($user_id)
    {
        $user = $this->storage->authFetchUserRepresentation($this->name(), $user_id);
        if (!$user) {
            throw new Exception\Package('User ' . $user_id . ' not found.', Exception\Package::USER_NOT_FOUND);
        }

        return $user;
    }

    /**
     * ------------ Package Management Functions ---------------
     */

    /**
     * Adds a package to a user
     *
     * @param   mixed   $user_id    Primary key of the user
     * @param   mixed   $package    String name of package, or instance of package.
     * @return  $this
     * @throws  Exception\Package
     * @uses    StorageDelegate    Lots.
     */
    public function addPackageToUser($user_id, $package)
    {
        $user = $this->loadUserRepresentation($user_id);

        // Check that the package exists:
        if (is_string($package) && class_exists($package)) {
            $package = new $package();
        } elseif (is_string($package) && !class_exists($package)) {
            throw new Exception\Package('Package: ' . $package . ' not found.', Exception\Package::PACKAGE_NOT_FOUND);
        }

        // Check that the package is correct:
        if (!$package instanceof Package) {
            throw new Exception\Package('Package: ' . get_class(
                $package
            ) . ' must inherit from Auth\Package', Exception\Package::PACKAGE_BAD_LINEAGE);
        }

        // All good. Add the package to the user:
        $this->storage->authAddPackageToUser($this->name(), $user, $package);

        // And rebuild the permissions:
        $this->buildPermissionsForUser($user_id);

        return $this;
    }

    /**
     * Removing a package from a user
     *
     * @param   mixed   $user_id    Primary Key of the user
     * @param   mixed   $package    String name of the package or instance of the package
     * @return  $this
     * @throws  Exception\Package
     * @uses    StorageDelegate
     */
    public function removePackageFromUser($user_id, $package)
    {
        $user = $this->loadUserRepresentation($user_id);

        // We kind of don't care if the package doesn't exist, so even if it doesn't,
        // just palm it off on the StorageDelegate and let it fail silently.
        if ((is_string($package) && class_exists($package)) || $package instanceof Package) {
            $package = (is_object($package)) ? $package : new $package();
            $this->storage->authRemovePackageFromUser($this->name(), $user, $package);

            // And rebuild the permissions:
            $this->buildPermissionsForUser($user_id);
        }

        return $this;
    }

    /**
     * Fetches the packages for a user.
     *
     * @param   mixed   $user_id    Primary key of the user
     * @return  array
     * @throws  Exception\Package
     * @uses    StorageDelegate
     */
    public function packagesForUser($user_id)
    {
        $user = $this->loadUserRepresentation($user_id);
        return (array)$this->storage->authFetchPackagesForUser($this->name(), $user);
    }


    /**
     * Checks to see if a user has a package or not. If package is not a valid Package
     * or doesn't exist, function will fail silently and return false
     *
     * @param   mixed   $user_id    Primary key of the user
     * @param   mixed   $package    String name of the package ot instance of the package
     * @return  bool
     * @throws  Exception\Package
     * @uses    StorageDelegate
     */
    public function userHasPackage($user_id, $package)
    {
        $user = $this->loadUserRepresentation($user_id);

        if ((is_string($package) && class_exists($package)) || $package instanceof Package) {
            $package = (is_object($package)) ? $package : new $package();
            return $this->storage->authUserHasPackage($this->name(), $user, $package);
        }

        return false;
    }


    /**
     * --------------- Permissions! -----------------
     */

    /**
     * Builds up the permissions for a user by looping through,
     * taking on the highest precedence of each tier.
     *
     * @param   mixed   $user_id    ID of the user to build for
     * @return  void
     */
    protected function buildPermissionsForUser($user_id)
    {
        // Make use of Collection to do some clever sorting:
        $all_packages = $this->packagesForUser($user_id);
        $sorted_packages = new Collection($all_packages);
        $sorted_packages->sortByMember('precedence');

        $permissions = array();
        foreach ($sorted_packages as $package) {
            foreach ($package->rules() as $name => $rule) {
                $permissions[$name] = $rule;
            }

            foreach ($package->callbacks() as $name => $callback) {
                $permissions[$name] = $callback;
            }
        }

        // And now process any overrides that this user has:
        $user = $this->loadUserRepresentation($user_id);
        $overrides = $this->storage->authFetchOverridesForUser($this->name(), $user);
        foreach ($overrides as $permission => $new_value) {
            if (array_key_exists($permission, $permissions)) {
                $permissions[$permission] = $new_value;
            }
        }

        $this->permissions_cache[$user_id] = $permissions;
    }

    /**
     * Can function. The most useful function in the whole thing
     *
     * @param   mixed   $user_id        User ID
     * @param   string  $permission     Permission name to check in the Package
     * @param   array   $args           Arguments to pass to a package callback
     * @return  bool    true = Yes they can. false = no they can't
     */
    public function userCan($user_id, $permission, array $args = array())
    {
        if (!array_key_exists($permission, $this->permissions_cache[$user_id])) {
            // No permission found. To be safe, we always return false in these
            // cases.
            return false;
        }

        $perm = $this->permissions_cache[$user_id][$permission];

        if (is_bool($perm)) {
            return $perm;
        } else {
            return call_user_func_array($perm, $args);
        }
    }


    /**
     * Can() is a shortcut for userCan() for the currently logged in user
     *
     * @param   string  $permission     Permission name to check in the Package
     * @param   array   $args           Arguments to pass to a package callback
     * @return  bool    true = Yes they can. false = no they can't
     */
    public function can($permission, array $args = array())
    {
        if (!$this->loggedIn()) {
            return false;
        }

        return $this->userCan($this->session->authRead($this->name()), $permission, $args);
    }


    /**
     * Overrides a permission for a user. You can use this to further customise
     * a Package for a specific user, for instance, allowing a regular user to blog
     * or revoking someone's commenting rights, all without setting up a new package.
     *
     * @param   mixed   $user_id        User ID to change
     * @param   string  $permission     Permission name to change
     * @param   bool    $new_value      New permission. true = yes, false = no.
     * @return  $this
     * @uses    StorageDelegate
     */
    public function overridePermissionForUser($user_id, $permission, $new_value)
    {
        $user = $this->loadUserRepresentation($user_id);

        if ($this->storage->authOverridePermissionForUser($this->name(), $user, $permission, $new_value)) {
            $this->buildPermissionsForUser($user_id);
        }

        return $this;
    }

    /**
     * Removes the overrides for a user, returning them to package settings.
     *
     * @param   mixed   $user_id    User ID to change
     * @return  $this
     * @uses    StorageDelegate
     */
    public function resetOverridesForUser($user_id)
    {
        $user = $this->loadUserRepresentation($user_id);
        if ($this->storage->authResetOverridesForUser($this->name(), $user)) {
            $this->buildPermissionsForUser($user_id);
        }

        return $this;
    }
}