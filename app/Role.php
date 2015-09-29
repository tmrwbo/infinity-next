<?php namespace App;

use App\Permission;
use Illuminate\Database\Eloquent\Model;

class Role extends Model {
	
	/**
	 * These constants represent the hard ID top-level system roles.
	 */
	const ID_ANONYMOUS     = 1;
	const ID_ADMIN         = 2;
	const ID_MODERATOR     = 3;
	const ID_OWNER         = 4;
	const ID_JANITOR       = 5;
	const ID_UNACCOUNTABLE = 6;
	const ID_REGISTERED    = 7;
	
	/**
	 * These constants represent the weights of hard ID top-level system roles.
	 */
	const WEIGHT_ANONYMOUS     = 0;
	const WEIGHT_ADMIN         = 100;
	const WEIGHT_MODERATOR     = 80;
	const WEIGHT_OWNER         = 60;
	const WEIGHT_JANITOR       = 40;
	const WEIGHT_UNACCOUNTABLE = 20;
	const WEIGHT_REGISTERED    = 30;
	
	/**
	 * The database table used by the model.
	 *
	 * @var string
	 */
	protected $table = 'roles';
	
	/**
	 * The table's primary key.
	 *
	 * @var string
	 */
	protected $primaryKey = 'role_id';
	
	/**
	 * The attributes that are mass assignable.
	 *
	 * @var array
	 */
	protected $fillable = [
		// These three together must be unique. (role,board_uri,caste)
		'role',       // The group name. Very loosely ties masks together.
		'board_uri',  // The board URI. Can be NULL to affect all boards.
		'caste',      // An internal name to separate roles into smaller groups.
		
		'name',       // Internal nickname. Passes through translator, so language tokens work.
		'capcode',    // Same as above, but can be null. If null, it provides no capcode when posting.
		
		'inherit_id', // PK for another Role that this directly inherits permissions from.
		'system',     // Boolean. If TRUE, it indicates the mask is a very important system role that should not be deleted.
		'weight',     // Determines the order of permissions when compiled into a mask.
	];
	
	/**
	 * Indicates their is no autoupdated timetsamps.
	 *
	 * @var boolean
	 */
	public $timestamps = false;
	
	
	public function board()
	{
		return $this->belongsTo('\App\Board', 'board_id');
	}
	
	public function inherits()
	{
		return $this->hasOne('\App\Role', 'role_id', 'inherit_id');
	}
	
	public function permissions()
	{
		return $this->belongsToMany("\App\Permission", 'role_permissions', 'role_id', 'permission_id')->withPivot('value');
	}
	
	public function users()
	{
		return $this->belongsToMany('\App\User', 'user_roles', 'role_id', 'user_id');
	}
	
	/**
	 * Returns a human-readable name for this role.
	 *
	 * @return string 
	 */
	public function getDisplayName()
	{
		return trans($this->name);
	}
	
	/**
	 * Returns owner role (found or created) for a specific board.
	 *
	 * @param  \App\Board  $board
	 * @return \App\Role
	 */
	public static function getOwnerRoleForBoard(Board $board)
	{
		return static::firstOrCreate([
			'role'       => "owner",
			'board_uri'  => $board->board_uri,
			'caste'      => NULL,
			'inherit_id' => Role::ID_OWNER,
			'name'       => "user.role.owner",
			'capcode'    => "user.role.owner",
			'system'     => false,
			'weight'     => Role::WEIGHT_OWNER + 5,
		]);
	}
	
	/**
	 * Returns the individual value for a requested permission.
	 *
	 * @param  \App\Permission  $permission
	 * @return boolean|null
	 */
	public function getPermission(Permission $permission)
	{
		foreach ($this->permissions as $thisPermission)
		{
			if ($thisPermission->permission_id == $permission->permission_id)
			{
				return !!$thisPermission->pivot->value;
			}
		}
		
		return null;
	}
	
	
	/**
	 * Builds a single role mask for all boards, called by name.
	 *
	 * @param  array|Collection  $roleMasks
	 * @return array
	 */
	public static function getRoleMaskByName($roleMasks)
	{
		$roles = static::whereIn('role', (array) $roleMasks)
			->orWhere('role_id', static::ID_ANONYMOUS)
			->with('permissions')
			->get();
		
		return static::getRolePermissions($roles);
	}
	
	/**
	 * Builds a single role mask for all boards, called by id.
	 *
	 * @param  array|integer  $roleIDs  Role primary keys to compile together.
	 * @return array
	 */
	public static function getRoleMaskByID($roleIDs)
	{
		$roles = static::whereIn('role_id', (array) $roleIDs)
			->orWhere('role_id', static::ID_ANONYMOUS)
			->with('permissions')
			->get();
		
		return static::getRolePermissions($roles);
	}
	
	/**
	 * Compiles a set of roles into a permission mask.
	 *
	 * @param  Collection  $roles  A Laravel collection of Role models.
	 * @return array
	 */
	protected static function getRolePermissions($roles)
	{
		$permissions = [];
		
		foreach ($roles as $role)
		{
			$inherited = [];
			
			if (is_numeric($role->inherit_id))
			{
				$inherited = static::getRolePermissions([$role->inherits]);
				
				foreach ($inherited as $board_uri => $inherited_permissions)
				{
					if ($board_uri == $role->board_uri || $board_uri == "")
					{
						foreach ($inherited_permissions as $permission_id => $value)
						{
							$permission = &$permissions[$role->board_uri][$permission_id];
							
							if (!isset($permission) || $permission !== 0)
							{
								$permission = (int) $value;
							}
						}
					}
				}
			}
			
			if (!isset($permissions[$role->board_uri]))
			{
				$permissions[$role->board_uri] = [];
			}
			
			foreach ($role->permissions as $permission)
			{
				$value = null;
				
				if (isset($inherited[null][$permission->permission_id]))
				{
					$value = (int) $inherited[null][$permission->permission_id];
				}
				
				if ($value !== 0)
				{
					if (isset($inherited[$role->board_uri][$permission->permission_id]))
					{
						$value = (int) $inherited[$role->board_uri][$permission->permission_id];
					}
					
					if ($value !== 0)
					{
						$value = !!$permission->pivot->value;
					}
				}
				
				if ($value)
				{
					$permissions[$role->board_uri][$permission->permission_id] = true;
				}
			}
		}
		
		return $permissions;
	}
	
	
	public function scopeWhereLevel($query, $role_id)
	{
		return $query->where(function($query) use ($role_id) {
			$query->where('inherit_id', '=', $role_id);
			$query->orWhere('role_id', '=', $role_id);
		});
	}
}
