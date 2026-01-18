<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use CarbonTrack\Models\UserGroup;
use Illuminate\Database\Capsule\Manager as DB;

class UserGroupService
{
    public function getAllGroups()
    {
        return UserGroup::orderBy('id', 'asc')->get();
    }

    public function getGroupById(int $id)
    {
        return UserGroup::find($id);
    }

    public function createGroup(array $data)
    {
        return UserGroup::create($data);
    }

    public function updateGroup(int $id, array $data)
    {
        $group = UserGroup::findOrFail($id);
        $group->update($data);
        return $group;
    }

    public function deleteGroup(int $id)
    {
        $group = UserGroup::findOrFail($id);
        $group->delete();
    }
}
