<?php

namespace PHPAuth;

/**
 * @author Liam Jack <cuonic@cuonic.com>
 * @license MIT
 */

class PHPAuth {
	private $database;
	private $isAuthenticated = false;
	private $authenticatedUser = NULL;

	public function __construct(Database $database) {
		$this->database = $database;

		if(isset($_COOKIE[Configuration::SESSION_COOKIE_NAME])) {
			$sessionUuid = $_COOKIE[Configuration::SESSION_COOKIE_NAME];

			if(!$this->isSessionValid($sessionUuid)) {
				deleteSessionCookie();
			}
		}
	}

	/**
	 * Allows a user to authenticate and creates a new session
	 * @param  string 	$email 		User's email address
	 * @param  string 	$password 	User's password
	 * @return session
	 * @throws Exception
	 */

	public function login($email, $password, $isPersistent = false) {
		if($this->isAuthenticated()) {
			// User is already authenticated
			throw new \Exception("already_authenticated");
		}

		// Validate email address
		User::validateEmail($email);

		// Validate password
		User::validatePassword($password);

		// Get user with provided email address
		$user = $this->database->getUserByEmail($email);

		if($user == NULL) {
			// User does not exist
			throw new \Exception("email_password_incorrect");
		}

		if(!$user->verifyPassword($password)) {
			// Provided password doesn't match the user's password
			throw new \Exception("email_password_incorrect");
		}

		// Create a new session
		$session = Session::createSession($user->getId(), $isPersistent);

		// Add session to database
		$this->database->addSession($session);

		// Set the user's session cookie
		$this->setSessionCookie($session->getUuid(), $session->getExpiryDate());

		// Set authenticated user
		$this->setAuthenticatedUser($user);
	}

	/**
	 * Creates a new user account
	 * @param 	string $email 			User's email address
	 * @param 	string $password 		User's desired password
	 * @param 	string $repeatPassword	User's desired password, repeated to prevent typos
	 * @throws 	Exception
	 */

	public function register($email, $password, $repeatPassword) {
		if(!Configuration::REGISTRATION_ENABLED) {
			throw new \Exception("registration_disabled");
		}

		if($this->isAuthenticated()) {
			// User is already authenticated
			throw new \Exception("already_authenticated");
		}

		// Validate email address
		User::validateEmail($email);

		// Validate password
		User::validatePassword($password);

		if($password !== $repeatPassword) {
			// Password and password confirmation do not match
			throw new \Exception("password_no_match");
		}

		if($this->database->doesUserExistByEmail($email)) {
			// User with this email address already exists
			throw new \Exception("email_used");
		}

		// Create new user
		$user = User::createUser($email, $password);

		// Add user to database
		$this->database->addUser($user);
	}

	/**
	 * Changes the authenticated user's password
	 * @param 	string 	$password
	 * @param 	string 	$newPassword
	 * @param 	string 	$repeatNewPassword
	 * @throws 	Exception
	 */

	public function changePassword($password, $newPassword, $repeatNewPassword) {
		if(!$this->isAuthenticated()) {
			// User is not authenticated
			throw new \Exception("not_authenticated");
		}

		$this->authenticatedUser->changePassword($password, $newPassword, $repeatPassword);
		$this->database->updateUser($this->authenticatedUser);
	}

	/**
	 * Changes the authenticated user's email address
	 * @param 	string 	$password
	 * @param 	string 	$newEmail
	 * @throws 	Exception
	 */

	public function changeEmail($password, $newEmail) {
		if(!$this->isAuthenticated()) {
			// User is not authenticated
			throw new \Exception("not_authenticated");
		}

		$this->authenticatedUser->changeEmail($password, $newEmail);
		$this->database->updateUser($this->authenticatedUser);
	}

	/**
	 * Checks whether a user's session is valid or not and performs
	 * modifications / deletions of sessions when necessary
	 * @param 	string 	$sessionUuid	The session's UUID
	 * @return 	bool
	 */

	public function isSessionValid($sessionUuid) {
		if($this->isAuthenticated()) {
			// Session already validated
			return true;
		}

		// Validate the session's UUID
		if(!Session::validateUuid($sessionUuid)) {
			return false;
		}

		// Fetch the session from the database
		$session = $this->database->getSession($sessionUuid);

		if($session == NULL) {
			// Session doesn't exist
			return false;
		}

		if(!$session->isValid()) {
			// Session is invalid, delete
			$this->database->deleteSession($sessionUuid);
			return false;
		}

		if($session->isUpdateRequired()) {
			// Session has been updated during verification, push update to database
			$this->database->updateSession($session);
		}

		// Session is valid, set authenticated user
		$this->setAuthenticatedUserById($session->getUserId());

		return true;
	}

	/**
	 * Indicates if the user is authenticated
	 * @return 	bool
	 */

	public function isAuthenticated() {
		return $this->isAuthenticated;
	}

	/**
	 * Returns the currently authenticated user, or NULL if no user is not authenticated
	 * @return 	User
	 */

	public function getAuthenticatedUser() {
		return $this->authenticatedUser;
	}

	/**
	 * Sets the currently authenticated user
	 * @param 	User 	$user
	 */

	private function setAuthenticatedUser(User $user) {
		$this->authenticatedUser = $user;
		$this->isAuthenticated = true;
	}

	/**
	 * Sets the currently authenticated user by User ID, fetching the user from database
	 * @param 	int 	$userId
	 */

	private function setAuthenticatedUserById($userId) {
		$this->authenticatedUser = $this->database->getUserById($userId);

		if($this->authenticatedUser == NULL) {
			// User doesn't exist
			$this->isAuthenticated = false;
		} else {
			$this->isAuthenticated = true;
		}
	}

	/**
	 * Sets the user's session cookie
	 * @param 	string 	$sessionUuid
	 * @param 	int 	$expiryDate
	 */

	public function setSessionCookie($sessionUuid, $expiryDate) {
		setcookie(
			Configuration::SESSION_COOKIE_NAME,
			$sessionUuid,
			$expiryDate,
			Configuration::SESSION_COOKIE_PATH,
			Configuration::SESSION_COOKIE_DOMAIN,
			Configuration::SESSION_COOKIE_SECURE,
			Configuration::SESSION_COOKIE_HTTPONLY
		);
	}

	/**
	 * Deletes the user's session cookie
	 */

	public function deleteSessionCookie() {
		unset($_COOKIE[Configuration::SESSION_COOKIE_NAME]);
		setSessionCookie(NULL, time() - 3600);
	}


}