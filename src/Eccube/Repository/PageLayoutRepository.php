<?php
/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) 2000-2015 LOCKON CO.,LTD. All Rights Reserved.
 *
 * http://www.lockon.co.jp/
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */


namespace Eccube\Repository;

use Eccube\Entity\Master\DeviceType;
use Eccube\Entity\PageLayout;
use Symfony\Component\Filesystem\Filesystem;

/**
 * PageLayoutRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class PageLayoutRepository extends AbstractRepository
{

    public function findUnusedBlocks(DeviceType $DeviceType, $pageId)
    {
        $em = $this
            ->getEntityManager();
        $blockRepo = $em->getRepository('Eccube\Entity\Block');
        $ownBlockPositions = $this->getByDeviceTypeAndId($DeviceType, $pageId)->getBlockPositions();
        $ids = array();
        foreach ($ownBlockPositions as $ownBlockPosition) {
            $ids[] = $ownBlockPosition->getBlock()->getId();
        }

        # $idsが空配列だと、$ids以外のblockを取得するSQLが生成されないため、存在しないidを入れる
        if (empty($ids)) {
            $ids[] = \Eccube\Entity\Block::UNUSED_BLOCK_ID;
        }

        return $blockRepo->createQueryBuilder('b')
            ->where('b.id not in (:ids)')
            ->setParameter(':ids', $ids)
            ->getQuery()
            ->getResult();
    }

    public function getByDeviceTypeAndId(DeviceType $DeviceType, $pageId)
    {
        $qb = $this->createQueryBuilder('p')
            ->select('p, bp, b')
            ->leftJoin('p.BlockPositions', 'bp', 'WITH', 'p.id = bp.page_id')
            ->leftJoin('bp.Block', 'b')
            ->andWhere('p.DeviceType = :DeviceType AND p.id = :pageId')
            ->addOrderBy('bp.target_id', 'ASC')
            ->addOrderBy('bp.block_row', 'ASC');

        $ownResult = $qb
            ->getQuery()
            ->setParameters(array(
                'DeviceType'  => $DeviceType,
                'pageId'        => $pageId,
            ))
            ->getSingleResult();

        $qb = $this->createQueryBuilder('p')
            ->select('p, bp, b')
            ->leftJoin('p.BlockPositions', 'bp', 'WITH', 'p.id = bp.page_id')
            ->leftJoin('bp.Block', 'b')
            ->andWhere('p.DeviceType = :DeviceType AND bp.anywhere = 1')
            ->addOrderBy('bp.target_id', 'ASC')
            ->addOrderBy('bp.block_row', 'ASC');

        $anyResults = $qb
            ->getQuery()
            ->setParameters(array(
                'DeviceType' => $DeviceType,
            ))
            ->getResult();

        $OwnBlockPosition = $ownResult->getBlockPositions();
        foreach ($anyResults as $anyResult) {
            $BlockPositions = $anyResult->getBlockPositions();
            foreach ($BlockPositions as $BlockPosition) {
                if (!$OwnBlockPosition->contains($BlockPosition)) {
                    $ownResult->addBlockPosition($BlockPosition);
                }
            }
        }

        return $ownResult;

    }

    public function getByUrl(DeviceType $DeviceType, $url)
    {
        $options = $this->app['config']['doctrine_cache'];
        $lifetime = $options['result_cache']['lifetime'];

        $qb = $this->createQueryBuilder('p')
            ->select('p, bp, b')
            ->leftJoin('p.BlockPositions', 'bp', 'WITH', 'p.id = bp.page_id')
            ->leftJoin('bp.Block', 'b')
            ->andWhere('p.DeviceType = :DeviceType AND p.url = :url')
            ->addOrderBy('bp.target_id', 'ASC')
            ->addOrderBy('bp.block_row', 'ASC');

        $ownResult = $qb
            ->getQuery()
            ->useResultCache(true, $lifetime)
            ->setParameters(array(
                'DeviceType' => $DeviceType,
                'url'  => $url,
            ))
            ->getSingleResult();

        $qb = $this->createQueryBuilder('p')
            ->select('p, bp, b')
            ->leftJoin('p.BlockPositions', 'bp', 'WITH', 'p.id = bp.page_id')
            ->leftJoin('bp.Block', 'b')
            ->andWhere('p.DeviceType = :DeviceType AND bp.anywhere = 1')
            ->addOrderBy('bp.target_id', 'ASC')
            ->addOrderBy('bp.block_row', 'ASC');

        $anyResults = $qb
            ->getQuery()
            ->useResultCache(true, $lifetime)
            ->setParameters(array(
                'DeviceType' => $DeviceType,
            ))
            ->getResult();

        $OwnBlockPosition = $ownResult->getBlockPositions();
        foreach ($anyResults as $anyResult) {
            $BlockPositions = $anyResult->getBlockPositions();
            foreach ($BlockPositions as $BlockPosition) {
                if (!$OwnBlockPosition->contains($BlockPosition)) {
                    $ownResult->addBlockPosition($BlockPosition);
                }
            }
        }

        return $ownResult;
    }

    public function newPageLayout(DeviceType $DeviceType)
    {
        $PageLayout = new \Eccube\Entity\PageLayout();
        $PageLayout
            ->setDeviceType($DeviceType)
            ->setEditFlg(PageLayout::EDIT_FLG_USER);

        return $PageLayout;
    }

    public function findOrCreate($page_id, DeviceType $DeviceType)
    {
        if (is_null($page_id)) {
            $PageLayout = $this
                ->newPageLayout($DeviceType);
            return $PageLayout;
        } else {
            return $this->getByDeviceTypeAndId($DeviceType, $page_id);
        }
    }

    /**
     * ページの属性を取得する.
     *
     * この関数は, dtb_pagelayout の情報を検索する.
     * $deviceTypeId は必須. デフォルト値は DEVICE_TYPE_PC.
     *
     * @access public
     * @param  \Eccube\Entity\Master\DeviceType  $DeviceType 端末種別ID
     * @param  string                            $where 追加の検索条件
     * @param  string[]                          $parameters 追加の検索パラメーター
     * @return array                             ページ属性の配列
     */
    public function getPageList(DeviceType $DeviceType, $where = null, $parameters = array())
    {
        $qb = $this->createQueryBuilder('l')
            ->orderBy('l.id', 'DESC')
            ->where('l.DeviceType = :DeviceType')
            ->setParameter('DeviceType', $DeviceType)
            ->andWhere('l.id <> 0')
            ->orderBy('l.id', 'ASC');
        if (!is_null($where)) {
            $qb->andWhere($where);
            foreach ($parameters as $key => $val) {
                $qb->setParameter($key, $val);
            }
        }

        $PageLayouts = $qb
            ->getQuery()
            ->getResult();

        return $PageLayouts;
    }

    /**
     * 書き込みパスの取得
     * User定義の場合： /html/user_data
     * そうでない場合： /app/template/{template_code}
     *
     * @param  boolean $isUser
     * @return string
     */
    public function getWriteTemplatePath($isUser = false)
    {
        return ($isUser) ? $this->app['config']['user_data_realdir'] : $this->app['config']['template_realdir'];
    }

    /**
     * 読み込みファイルの取得
     *
     * 1. template_realdir
     *      app/template/{template_code}
     * 2. template_default_readldir
     *      src/Eccube/Resource/template/default
     *
     * @param string $fileName
     * @param  boolean $isUser
     *
     * @return array
     */
    public function getReadTemplateFile($fileName, $isUser = false)
    {
        if ($isUser) {
            $readPaths = array(
                $this->app['config']['user_data_realdir'],
            );
        } else {
            $readPaths = array(
                $this->app['config']['template_realdir'],
                $this->app['config']['template_default_realdir'],
            );
        }

        foreach ($readPaths as $readPath) {
            $filePath = $readPath . '/' . $fileName . '.twig';
            $fs = new Filesystem();
            if ($fs->exists($filePath)) {
                return array(
                    'file_name' => $fileName,
                    'tpl_data' => file_get_contents($filePath),
                );
            }
        }
    }
}
