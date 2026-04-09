<?php

/*
 *     ____            ____        __  ____
 *    / __ )___  ___  / / /___  __/  |/  (_)___  ___
 *   / __  / _ \/ _ \/ / __/ / / / /|_/ / / __ \/ _ \
 *  / /_/ /  __/  __/ / /_/ /_/ / /  / / / / / /  __/
 * /_____/\___/\___/_/\__/\__, /_/  /_/_/_/ /_/\___/
 *                       /____/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author Ayrz
 * @team BeeltyMine
 * 
 * 
 */

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\block\Block;
use pocketmine\block\Water;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\player\PlayerExhaustEvent;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\sound\SimpleLevelSound;
use pocketmine\world\sound\Sound;
use function floor;
use function max;

class Spear extends TieredTool implements Releasable{
	private const ATTACK_POINT_OFFSET = 2;
	private const STAB_COOLDOWN_TICKS = 20;
	private const HOLD_HIT_COOLDOWN_TICKS = 10;
	private const HOLD_HITBOX_GROW_XZ = 1.5;
	private const HOLD_HITBOX_GROW_Y = 1.0;
	private const HOLD_FORWARD_OFFSET = 1.5;
	private const HOLD_DAMAGE_MULTIPLIER_ENGAGED = 1.0;
	private const HOLD_DAMAGE_MULTIPLIER_TIRED = 1.0;
	private const HOLD_DAMAGE_MULTIPLIER_DISENGAGED = 1.0;
	private const MINIMUM_CHARGE_RELATIVE_SPEED = 0.255; // 5.1 blocks/second
	private const CHARGE_STAGE_ENGAGED_MAX_TICKS = 10;
	private const CHARGE_STAGE_TIRED_MAX_TICKS = 20;
	private const CHARGE_KNOCKBACK_ENGAGED = 0.5;
	private const CHARGE_KNOCKBACK_TIRED = 0.35;
	private const CHARGE_KNOCKBACK_DISENGAGED = 0.0;
	private const CHARGE_TARGET_MIN_DOT = 0.7;
	private const CHARGE_TARGET_HITBOX_MARGIN = 0.125;
	private const JAB_MAX_DISTANCE = 4.5;
	private const JAB_MIN_DISTANCE = 2.0;
	private const JAB_HITBOX_MARGIN = 0.125;
	private const LUNGE_HORIZONTAL_BOOST_PER_LEVEL = 0.458;
	private const LUNGE_EXHAUSTION_PER_LEVEL = 4.0;
	private const LUNGE_MIN_FOOD = 6.0;
	private const STAB_MAX_DISTANCE = 5.0;
	private const STAB_MIN_DOT = 0.866;
	private const MINIMUM_STAB_SPEED = 0.13;

	/** @var array<int, int> maps player ID => next tick when charge can connect again */
	private static array $chargeConnectionCooldownUntil = [];
	/** @var array<int, Vector3> maps player ID => last sampled position for charge speed */
	private static array $lastChargeSamplePosition = [];
	/** @var array<int, int> maps player ID => last sampled tick for charge speed */
	private static array $lastChargeSampleTick = [];

	public function getAttackPoints() : int{
		return max(1, $this->tier->getBaseAttackPoints() - self::ATTACK_POINT_OFFSET);
	}

	public function onClickAir(Player $player, Vector3 $directionVector, array &$returnedItems) : ItemUseResult{
		$player->getWorld()->addSound($player->getPosition(), $this->getUseSound());
		return ItemUseResult::SUCCESS;
	}

	public function getCooldownTicks() : int{
		// Keep jab/charge cooldown handling manual to avoid delaying charge-start on right-click use.
		return 0;
	}

	public function getJabCooldownTicks() : int{
		return self::STAB_COOLDOWN_TICKS;
	}

	public function getCooldownTag() : ?string{
		return ItemCooldownTags::SPEAR;
	}

	public function canStartUsingItem(Player $player) : bool{
		return true;
	}

	public function getJabMaxDistance() : float{
		return self::JAB_MAX_DISTANCE;
	}

	public function getJabMinDistance() : float{
		return self::JAB_MIN_DISTANCE;
	}

	public function getJabHitboxMargin() : float{
		return self::JAB_HITBOX_MARGIN;
	}

	public function getLungeLevel() : int{
		return $this->getEnchantmentLevel(VanillaEnchantments::LUNGE());
	}

	public function activateLunge(Player $player) : bool{
		$lungeLevel = $this->getLungeLevel();
		if(!$this->canActivateLunge($player, $lungeLevel)){
			return false;
		}

		$direction = $player->getDirectionVector()->withComponents(null, 0.0, null);
		if($direction->lengthSquared() <= 0){
			return false;
		}

		$boost = $direction
			->normalize()
			->multiply(self::LUNGE_HORIZONTAL_BOOST_PER_LEVEL * $lungeLevel);
		$player->setMotion($player->getMotion()->addVector($boost));
		$player->getWorld()->addSound($player->getPosition(), new SimpleLevelSound($this->getLungeSoundEventId($lungeLevel)));

		if($player->isSurvival(true) || $player->isAdventure(true)){
			$player->getHungerManager()->exhaust(self::LUNGE_EXHAUSTION_PER_LEVEL * $lungeLevel, PlayerExhaustEvent::CAUSE_ATTACK);
		}

		return true;
	}

	private function canActivateLunge(Player $player, int $lungeLevel) : bool{
		if($lungeLevel <= 0){
			return false;
		}

		if($player->isGliding() || $player->isSwimming() || $player->isUnderwater()){
			return false;
		}

		$position = $player->getPosition();
		$x = (int) floor($position->x);
		$y = (int) floor($position->y);
		$z = (int) floor($position->z);
		$world = $player->getWorld();
 
		if($world->getBlockAt($x, $y, $z) instanceof Water || $world->getBlockAt($x, $y - 1, $z) instanceof Water){
			return false;
		}

		if(($player->isSurvival(true) || $player->isAdventure(true)) && $player->getHungerManager()->getFood() < self::LUNGE_MIN_FOOD){
			return false;
		}

		return true;
	}

	public function whileUsing(Player $player) : void{
		$playerVelocity = $this->sampleChargeVelocity($player);

		if(!$this->canConnectChargeHit($player)){
			return;
		}

		$world = $player->getWorld();
		[$damageMultiplier, $knockBack] = $this->getChargeStageCombatValues($player->getItemUseDuration());

		$lookDirection = $player->getDirectionVector()->normalize();
		$direction = $lookDirection->multiply(self::HOLD_FORWARD_OFFSET);
		$hitBox = $player->getBoundingBox()
			->expandedCopy(self::HOLD_HITBOX_GROW_XZ, self::HOLD_HITBOX_GROW_Y, self::HOLD_HITBOX_GROW_XZ)
			->offset($direction->x, $direction->y, $direction->z);
		$targets = $this->findChargeTargets($player, $lookDirection, $hitBox);
		if(count($targets) === 0){
			return;
		}

		$didHit = false;
		$playerPos = $player->getPosition();
		foreach($targets as $target){
			$targetPos = $target->getPosition()->add(0, $target->size->getHeight() / 2, 0);
			$toTarget = $targetPos->subtractVector($playerPos)->normalize();
			$relativeVelocity = $playerVelocity->subtractVector($target->getMotion());

			$closingSpeed = $relativeVelocity->dot($toTarget);
			if($closingSpeed < self::MINIMUM_CHARGE_RELATIVE_SPEED){
				continue;
			}

			$relativeSpeedBps = $relativeVelocity->length() * 20;
			$finalDamage = max(1.0, $relativeSpeedBps * $this->getChargeSpeedDamageMultiplier() * $damageMultiplier);
			$damageEvent = new EntityDamageByEntityEvent(
				$player,
				$target,
				EntityDamageEvent::CAUSE_ENTITY_ATTACK,
				$finalDamage
			);
			$damageEvent->setModifier(0.0, EntityDamageEvent::MODIFIER_STRENGTH);
			$damageEvent->setModifier(0.0, EntityDamageEvent::MODIFIER_WEAKNESS);
			$damageEvent->setKnockBack($knockBack);
			$target->attack($damageEvent);
			if(!$damageEvent->isCancelled()){
				$didHit = true;
			}
		}

		if($didHit){
			$this->setChargeConnectionCooldown($player);
			$world->addSound($player->getPosition(), $this->getAttackHitSound());
		}
	}

	private function getChargeSpeedDamageMultiplier() : float{
		return match($this->tier){
			ToolTier::WOOD, ToolTier::GOLD => 0.7,
			ToolTier::STONE, ToolTier::COPPER => 0.82,
			ToolTier::IRON => 0.95,
			ToolTier::DIAMOND => 1.075,
			ToolTier::NETHERITE => 1.2,
		};
	}

	/**
	 * @return Living[]
	 */
	private function findChargeTargets(Player $player, Vector3 $lookDirection, AxisAlignedBB $hitBox) : array{
		$eyePos = $player->getEyePos();
		$traceEnd = $eyePos->addVector($lookDirection->multiply(self::JAB_MAX_DISTANCE));
		$candidates = [];
		foreach($player->getWorld()->getNearbyEntities($hitBox, $player) as $entity){
			if(!$entity instanceof Living || !$entity->isAlive()){
				continue;
			}

			$targetPos = $entity->getPosition()->add(0, $entity->size->getHeight() / 2, 0);
			$distance = $eyePos->distance($targetPos);
			if($distance > self::JAB_MAX_DISTANCE){
				continue;
			}

			$expandedHitBox = $entity->getBoundingBox()->expandedCopy(self::CHARGE_TARGET_HITBOX_MARGIN, self::CHARGE_TARGET_HITBOX_MARGIN, self::CHARGE_TARGET_HITBOX_MARGIN);
			$toTarget = $targetPos->subtractVector($eyePos)->normalize();
			$dot = $lookDirection->dot($toTarget);
			if($dot < self::CHARGE_TARGET_MIN_DOT){
				continue;
			}

			if($expandedHitBox->calculateIntercept($eyePos, $traceEnd) === null){
				continue;
			}

			$score = $dot - ($distance / self::JAB_MAX_DISTANCE) * 0.15;
			$candidates[] = [
				"entity" => $entity,
				"score" => $score,
			];
		}

		usort($candidates, static fn(array $a, array $b) : int => $b["score"] <=> $a["score"]);

		$targets = [];
		foreach($candidates as $candidate){
			$targets[] = $candidate["entity"];
		}

		return $targets;
	}

	private function sampleChargeVelocity(Player $player) : Vector3{
		$playerId = $player->getId();
		$currentTick = $player->getServer()->getTick();
		$currentPosition = $player->getPosition();

		$lastPosition = self::$lastChargeSamplePosition[$playerId] ?? null;
		$lastTick = self::$lastChargeSampleTick[$playerId] ?? null;

		self::$lastChargeSamplePosition[$playerId] = $currentPosition;
		self::$lastChargeSampleTick[$playerId] = $currentTick;

		if($lastPosition === null || $lastTick === null || $currentTick <= $lastTick){
			return Vector3::zero();
		}

		$tickDelta = $currentTick - $lastTick;
		return $currentPosition->subtractVector($lastPosition)->divide((float) $tickDelta);
	}

	private function canConnectChargeHit(Player $player) : bool{
		return (self::$chargeConnectionCooldownUntil[$player->getId()] ?? 0) <= $player->getServer()->getTick();
	}

	private function setChargeConnectionCooldown(Player $player) : void{
		self::$chargeConnectionCooldownUntil[$player->getId()] = $player->getServer()->getTick() + self::HOLD_HIT_COOLDOWN_TICKS;
	}

	/**
	 * @return array{float, float} [damageMultiplier, knockback]
	 */
	private function getChargeStageCombatValues(int $useDuration) : array{
		if($useDuration < self::CHARGE_STAGE_ENGAGED_MAX_TICKS){
			return [self::HOLD_DAMAGE_MULTIPLIER_ENGAGED, self::CHARGE_KNOCKBACK_ENGAGED];
		}

		if($useDuration < self::CHARGE_STAGE_TIRED_MAX_TICKS){
			return [self::HOLD_DAMAGE_MULTIPLIER_TIRED, self::CHARGE_KNOCKBACK_TIRED];
		}

		return [self::HOLD_DAMAGE_MULTIPLIER_DISENGAGED, self::CHARGE_KNOCKBACK_DISENGAGED];
	}

	/**
	 * @param Item[] &$returnedItems
	 */
	public function onSpearStab(Player $player, float $movementSpeed, array &$returnedItems) : void{
		if($player->hasItemCooldown($this)){
			return;
		}

		$world = $player->getWorld();
		$player->resetItemCooldown($this, self::STAB_COOLDOWN_TICKS);
		$world->addSound($player->getPosition(), $this->getUseSound());

		if($movementSpeed < self::MINIMUM_STAB_SPEED || !$player->isSprinting()){
			$world->addSound($player->getPosition(), $this->getMissSound());
			return;
		}

		$eyePos = $player->getEyePos();
		$direction = $player->getDirectionVector()->normalize();
		$searchBox = $player->getBoundingBox()->expandedCopy(self::STAB_MAX_DISTANCE, self::STAB_MAX_DISTANCE, self::STAB_MAX_DISTANCE);

		$bestScore = -1.0;
		$target = null;

		foreach($world->getNearbyEntities($searchBox, $player) as $entity){
			if(!$entity instanceof Living || !$entity->isAlive()){
				continue;
			}

			$targetPos = $entity->getPosition()->add(0, $entity->getEyeHeight() * 0.5, 0);
			$distance = $eyePos->distance($targetPos);
			if($distance > self::STAB_MAX_DISTANCE){
				continue;
			}

			$toEntity = $targetPos->subtractVector($eyePos)->normalize();
			$dot = $direction->dot($toEntity);
			if($dot < self::STAB_MIN_DOT){
				continue;
			}

			$score = $dot - ($distance / self::STAB_MAX_DISTANCE) * 0.1;
			if($score <= $bestScore){
				continue;
			}

			$bestScore = $score;
			$target = $entity;
		}

		if($target !== null){
			$damageEvent = new EntityDamageByEntityEvent(
				$player,
				$target,
				EntityDamageEvent::CAUSE_ENTITY_ATTACK,
				$this->getAttackPoints()
			);
			$target->attack($damageEvent);

			if(!$damageEvent->isCancelled()){
				$this->onAttackEntity($target, $returnedItems);
				$world->addSound($player->getPosition(), $this->getAttackHitSound());
				return;
			}
		}

		$world->addSound($player->getPosition(), $this->getMissSound());
	}

	public function onDestroyBlock(Block $block, array &$returnedItems) : bool{
		if(!$block->getBreakInfo()->breaksInstantly()){
			return $this->applyDamage(2);
		}

		return false;
	}

	public function onAttackEntity(Entity $victim, array &$returnedItems) : bool{
		return $this->applyDamage(1);
	}

	public function getAttackHitSound() : Sound{
		return new SimpleLevelSound($this->getHitSoundEventId());
	}

	public function getUseSound() : Sound{
		return new SimpleLevelSound($this->getUseSoundEventId());
	}

	public function getMissSound() : Sound{
		return new SimpleLevelSound($this->getMissSoundEventId());
	}

	private function getHitSoundEventId() : int{
		return match($this->tier){
			ToolTier::WOOD => LevelSoundEvent::ITEM_WOODEN_SPEAR_ATTACK_HIT,
			ToolTier::STONE => LevelSoundEvent::ITEM_STONE_SPEAR_ATTACK_HIT,
			ToolTier::COPPER => LevelSoundEvent::ITEM_COPPER_SPEAR_ATTACK_HIT,
			ToolTier::IRON => LevelSoundEvent::ITEM_IRON_SPEAR_ATTACK_HIT,
			ToolTier::GOLD => LevelSoundEvent::ITEM_GOLDEN_SPEAR_ATTACK_HIT,
			ToolTier::DIAMOND => LevelSoundEvent::ITEM_DIAMOND_SPEAR_ATTACK_HIT,
			ToolTier::NETHERITE => LevelSoundEvent::ITEM_NETHERITE_SPEAR_ATTACK_HIT,
		};
	}

	private function getUseSoundEventId() : int{
		return match($this->tier){
			ToolTier::WOOD => LevelSoundEvent::ITEM_WOODEN_SPEAR_USE,
			ToolTier::STONE => LevelSoundEvent::ITEM_STONE_SPEAR_USE,
			ToolTier::COPPER => LevelSoundEvent::ITEM_COPPER_SPEAR_USE,
			ToolTier::IRON => LevelSoundEvent::ITEM_IRON_SPEAR_USE,
			ToolTier::GOLD => LevelSoundEvent::ITEM_GOLDEN_SPEAR_USE,
			ToolTier::DIAMOND => LevelSoundEvent::ITEM_DIAMOND_SPEAR_USE,
			ToolTier::NETHERITE => LevelSoundEvent::ITEM_NETHERITE_SPEAR_USE,
		};
	}

	private function getMissSoundEventId() : int{
		return match($this->tier){
			ToolTier::WOOD => LevelSoundEvent::ITEM_WOODEN_SPEAR_ATTACK_MISS,
			ToolTier::STONE => LevelSoundEvent::ITEM_STONE_SPEAR_ATTACK_MISS,
			ToolTier::COPPER => LevelSoundEvent::ITEM_COPPER_SPEAR_ATTACK_MISS,
			ToolTier::IRON => LevelSoundEvent::ITEM_IRON_SPEAR_ATTACK_MISS,
			ToolTier::GOLD => LevelSoundEvent::ITEM_GOLDEN_SPEAR_ATTACK_MISS,
			ToolTier::DIAMOND => LevelSoundEvent::ITEM_DIAMOND_SPEAR_ATTACK_MISS,
			ToolTier::NETHERITE => LevelSoundEvent::ITEM_NETHERITE_SPEAR_ATTACK_MISS,
		};
	}

	private function getLungeSoundEventId(int $lungeLevel) : int{
		return match($lungeLevel){
			1 => LevelSoundEvent::ITEM_ENCHANT_LUNGE1,
			2 => LevelSoundEvent::ITEM_ENCHANT_LUNGE2,
			default => LevelSoundEvent::ITEM_ENCHANT_LUNGE3,
		};
	}
}
