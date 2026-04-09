<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @team BeeltyMine
 * @link http://www.beeltymine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\block\utils;

use pocketmine\entity\object\ItemEntity;
use pocketmine\utils\SingletonTrait;
use pocketmine\world\Position;
use pocketmine\world\World;

final class HopperDataStore{
	use SingletonTrait;

	/** @var array<int, array<int, array<int, ItemEntity>>> */
	private array $assignedEntities = [];

	/**
	 * @return ItemEntity[]
	 * @phpstan-return array<int, ItemEntity>
	 */
	public function getAssignedEntities(Position $position) : array{
		[$worldId, $chunkHash, $blockHash] = $this->getHashes($position);
		return $this->assignedEntities[$worldId][$chunkHash][$blockHash] ?? [];
	}

	public function assignEntity(Position $position, ItemEntity $entity) : void{
		[$worldId, $chunkHash, $blockHash] = $this->getHashes($position);
		$this->assignedEntities[$worldId][$chunkHash][$blockHash][$entity->getId()] = $entity;
	}

	public function unassignEntity(Position $position, ItemEntity $entity) : void{
		[$worldId, $chunkHash, $blockHash] = $this->getHashes($position);
		if(!isset($this->assignedEntities[$worldId][$chunkHash][$blockHash])){
			return;
		}

		unset($this->assignedEntities[$worldId][$chunkHash][$blockHash][$entity->getId()]);
		$this->cleanupEmptyEntries($worldId, $chunkHash, $blockHash);
	}

	public function removeBlock(Position $position) : void{
		[$worldId, $chunkHash, $blockHash] = $this->getHashes($position);
		unset($this->assignedEntities[$worldId][$chunkHash][$blockHash]);
		$this->cleanupEmptyEntries($worldId, $chunkHash, $blockHash);
	}

	public function removeChunk(World $world, int $chunkX, int $chunkZ) : void{
		$worldId = $world->getId();
		$chunkHash = World::chunkHash($chunkX, $chunkZ);
		unset($this->assignedEntities[$worldId][$chunkHash]);
		if(($this->assignedEntities[$worldId] ?? []) === []){
			unset($this->assignedEntities[$worldId]);
		}
	}

	public function removeWorld(World $world) : void{
		unset($this->assignedEntities[$world->getId()]);
	}

	/**
	 * @return array{int, int, int}
	 */
	private function getHashes(Position $position) : array{
		$floorX = $position->getFloorX();
		$floorY = $position->getFloorY();
		$floorZ = $position->getFloorZ();

		return [
			$position->getWorld()->getId(),
			World::chunkHash($floorX >> 4, $floorZ >> 4),
			World::blockHash($floorX, $floorY, $floorZ)
		];
	}

	private function cleanupEmptyEntries(int $worldId, int $chunkHash, int $blockHash) : void{
		if(($this->assignedEntities[$worldId][$chunkHash][$blockHash] ?? []) === []){
			unset($this->assignedEntities[$worldId][$chunkHash][$blockHash]);
		}
		if(($this->assignedEntities[$worldId][$chunkHash] ?? []) === []){
			unset($this->assignedEntities[$worldId][$chunkHash]);
		}
		if(($this->assignedEntities[$worldId] ?? []) === []){
			unset($this->assignedEntities[$worldId]);
		}
	}
}