<?php

declare(strict_types=1);

namespace App\Modules\Configs\Permissions\Services;

use App\Modules\Configs\Permissions\Models\Permission;
use App\Modules\Configs\Roles\Models\Role;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resuelve el catálogo de permisos y sus asignaciones.
 * El cruce va en dos pasos porque el catálogo está en la BD central y los pivotes en la del tenant.
 */
class PermissionService
{
    /**
     * Catálogo completo con su módulo cargado.
     */
    public function available(): Collection
    {
        return Permission::with('module')
            ->orderBy('group')
            ->orderBy('order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Devuelve el catálogo en dos niveles —módulo y grupo—, que es como lo pinta la matriz.
     * Los permisos sin módulo caen en un bloque propio, que va el último.
     *
     * @return array<int, array{module: ?string, icon: ?string, groups: array}>
     */
    public function tree(): array
    {
        return $this->available()
            ->groupBy(fn (Permission $permission) => $permission->module?->name ?? '')
            ->sortBy(fn (Collection $permissions, string $module) => $module === ''
                ? PHP_INT_MAX
                : $permissions->first()->module->order)
            ->map(fn (Collection $permissions, string $module) => [
                'module' => $module ?: null,
                'icon' => $permissions->first()->module?->icon,
                'groups' => $permissions
                    ->groupBy('group')
                    ->map(fn (Collection $grouped, string $group) => [
                        'group' => $group,
                        'permissions' => $grouped,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Ids de los permisos asignados a un rol.
     */
    public function assignedIds(Role $role): array
    {
        return DB::table('permission_role')
            ->where('role_id', $role->id)
            ->pluck('permission_id')
            ->all();
    }

    /**
     * UUIDs de los permisos asignados a un rol, que es lo que consume el frontend.
     */
    public function assignedUuids(Role $role): array
    {
        $ids = $this->assignedIds($role);

        if (! $ids) {
            return [];
        }

        return Permission::whereIn('id', $ids)->pluck('uuid')->all();
    }

    /**
     * Reemplaza los permisos de un rol por el conjunto recibido.
     *
     * @param  array<int, string>  $uuids
     */
    public function syncRolePermissions(Role $role, array $uuids): void
    {
        $ids = Permission::whereIn('uuid', $uuids)->pluck('id')->all();

        DB::transaction(function () use ($role, $ids) {
            DB::table('permission_role')->where('role_id', $role->id)->delete();

            if (! $ids) {
                return;
            }

            DB::table('permission_role')->insert(
                array_map(fn (int $id) => ['role_id' => $role->id, 'permission_id' => $id], $ids),
            );
        });
    }

    /**
     * Nombres de los permisos efectivos de un usuario: los de sus roles más los asignados directamente.
     *
     * @param  array<int, int>  $roleIds
     * @return array<int, string>
     */
    public function namesForUser(int $userId, array $roleIds): array
    {
        $permissionIds = DB::table('permission_user')
            ->where('user_id', $userId)
            ->pluck('permission_id')
            ->all();

        if ($roleIds) {
            $permissionIds = array_merge($permissionIds, DB::table('permission_role')
                ->whereIn('role_id', $roleIds)
                ->pluck('permission_id')
                ->all());
        }

        $permissionIds = array_unique($permissionIds);

        if (! $permissionIds) {
            return [];
        }

        return Permission::whereIn('id', $permissionIds)->pluck('name')->all();
    }
}
