<?php

namespace GetId3\Module\Audio;

use GetId3\Handler\BaseHandler;
use GetId3\Lib\Helper;

/////////////////////////////////////////////////////////////////
/// GetId3() by James Heinrich <info@getid3.org>               //
//  available at http://getid3.sourceforge.net                 //
//            or http://www.getid3.org                         //
/////////////////////////////////////////////////////////////////
// See readme.txt for more details                             //
/////////////////////////////////////////////////////////////////
//                                                             //
// module.audio.voc.php                                        //
// module for analyzing Creative VOC Audio files               //
// dependencies: NONE                                          //
//                                                            ///
/////////////////////////////////////////////////////////////////

/**
 * module for analyzing Creative VOC Audio files
 *
 * @author James Heinrich <info@getid3.org>
 *
 * @link http://getid3.sourceforge.net
 * @link http://www.getid3.org
 */
class Voc extends BaseHandler
{
    /**
     * @return bool
     */
    public function analyze()
    {
        $info = &$this->getid3->info;

        $OriginalAVdataOffset = $info['avdataoffset'];
        fseek($this->getid3->fp, $info['avdataoffset'], SEEK_SET);
        $VOCheader = fread($this->getid3->fp, 26);

        $magic = 'Creative Voice File';
        if (substr($VOCheader, 0, 19) != $magic) {
            $info['error'][] = 'Expecting "'.Helper::PrintHexBytes($magic).'" at offset '.$info['avdataoffset'].', found "'.Helper::PrintHexBytes(substr($VOCheader, 0, 19)).'"';

            return false;
        }

        // shortcuts
        $thisfile_audio = &$info['audio'];
        $info['voc'] = array();
        $thisfile_voc = &$info['voc'];

        $info['fileformat'] = 'voc';
        $thisfile_audio['dataformat'] = 'voc';
        $thisfile_audio['bitrate_mode'] = 'cbr';
        $thisfile_audio['lossless'] = true;
        $thisfile_audio['channels'] = 1; // might be overriden below
        $thisfile_audio['bits_per_sample'] = 8; // might be overriden below

        // byte #     Description
        // ------     ------------------------------------------
        // 00-12      'Creative Voice File'
        // 13         1A (eof to abort printing of file)
        // 14-15      Offset of first datablock in .voc file (std 1A 00 in Intel Notation)
        // 16-17      Version number (minor,major) (VOC-HDR puts 0A 01)
        // 18-19      2's Comp of Ver. # + 1234h (VOC-HDR puts 29 11)

        $thisfile_voc['header']['datablock_offset'] = Helper::LittleEndian2Int(substr($VOCheader, 20, 2));
        $thisfile_voc['header']['minor_version'] = Helper::LittleEndian2Int(substr($VOCheader, 22, 1));
        $thisfile_voc['header']['major_version'] = Helper::LittleEndian2Int(substr($VOCheader, 23, 1));

        do {
            $BlockOffset = ftell($this->getid3->fp);
            $BlockData = fread($this->getid3->fp, 4);
            $BlockType = ord($BlockData{0});
            $BlockSize = Helper::LittleEndian2Int(substr($BlockData, 1, 3));
            $ThisBlock = array();

            Helper::safe_inc($thisfile_voc['blocktypes'][$BlockType], 1);
            switch ($BlockType) {
                case 0:  // Terminator
                    // do nothing, we'll break out of the loop down below
                    break;

                case 1:  // Sound data
                    $BlockData .= fread($this->getid3->fp, 2);
                    if ($info['avdataoffset'] <= $OriginalAVdataOffset) {
                        $info['avdataoffset'] = ftell($this->getid3->fp);
                    }
                    fseek($this->getid3->fp, $BlockSize - 2, SEEK_CUR);

                    $ThisBlock['sample_rate_id'] = Helper::LittleEndian2Int(substr($BlockData, 4, 1));
                    $ThisBlock['compression_type'] = Helper::LittleEndian2Int(substr($BlockData, 5, 1));

                    $ThisBlock['compression_name'] = $this->VOCcompressionTypeLookup($ThisBlock['compression_type']);
                    if ($ThisBlock['compression_type'] <= 3) {
                        $thisfile_voc['compressed_bits_per_sample'] = Helper::CastAsInt(str_replace('-bit', '', $ThisBlock['compression_name']));
                    }

                    // Less accurate sample_rate calculation than the Extended block (#8) data (but better than nothing if Extended Block is not available)
                    if (empty($thisfile_audio['sample_rate'])) {
                        // SR byte = 256 - (1000000 / sample_rate)
                        $thisfile_audio['sample_rate'] = Helper::trunc((1000000 / (256 - $ThisBlock['sample_rate_id'])) / $thisfile_audio['channels']);
                    }
                    break;

                case 2:  // Sound continue
                case 3:  // Silence
                case 4:  // Marker
                case 6:  // Repeat
                case 7:  // End repeat
                    // nothing useful, just skip
                    fseek($this->getid3->fp, $BlockSize, SEEK_CUR);
                    break;

                case 8:  // Extended
                    $BlockData .= fread($this->getid3->fp, 4);

                    //00-01  Time Constant:
                    //   Mono: 65536 - (256000000 / sample_rate)
                    // Stereo: 65536 - (256000000 / (sample_rate * 2))
                    $ThisBlock['time_constant'] = Helper::LittleEndian2Int(substr($BlockData, 4, 2));
                    $ThisBlock['pack_method'] = Helper::LittleEndian2Int(substr($BlockData, 6, 1));
                    $ThisBlock['stereo'] = (bool) Helper::LittleEndian2Int(substr($BlockData, 7, 1));

                    $thisfile_audio['channels'] = ($ThisBlock['stereo'] ? 2 : 1);
                    $thisfile_audio['sample_rate'] = Helper::trunc((256000000 / (65536 - $ThisBlock['time_constant'])) / $thisfile_audio['channels']);
                    break;

                case 9:  // data block that supersedes blocks 1 and 8. Used for stereo, 16 bit
                    $BlockData .= fread($this->getid3->fp, 12);
                    if ($info['avdataoffset'] <= $OriginalAVdataOffset) {
                        $info['avdataoffset'] = ftell($this->getid3->fp);
                    }
                    fseek($this->getid3->fp, $BlockSize - 12, SEEK_CUR);

                    $ThisBlock['sample_rate'] = Helper::LittleEndian2Int(substr($BlockData, 4, 4));
                    $ThisBlock['bits_per_sample'] = Helper::LittleEndian2Int(substr($BlockData, 8, 1));
                    $ThisBlock['channels'] = Helper::LittleEndian2Int(substr($BlockData, 9, 1));
                    $ThisBlock['wFormat'] = Helper::LittleEndian2Int(substr($BlockData, 10, 2));

                    $ThisBlock['compression_name'] = $this->VOCwFormatLookup($ThisBlock['wFormat']);
                    if ($this->VOCwFormatActualBitsPerSampleLookup($ThisBlock['wFormat'])) {
                        $thisfile_voc['compressed_bits_per_sample'] = $this->VOCwFormatActualBitsPerSampleLookup($ThisBlock['wFormat']);
                    }

                    $thisfile_audio['sample_rate'] = $ThisBlock['sample_rate'];
                    $thisfile_audio['bits_per_sample'] = $ThisBlock['bits_per_sample'];
                    $thisfile_audio['channels'] = $ThisBlock['channels'];
                    break;

                default:
                    $info['warning'][] = 'Unhandled block type "'.$BlockType.'" at offset '.$BlockOffset;
                    fseek($this->getid3->fp, $BlockSize, SEEK_CUR);
                    break;
            }

            if (!empty($ThisBlock)) {
                $ThisBlock['block_offset'] = $BlockOffset;
                $ThisBlock['block_size'] = $BlockSize;
                $ThisBlock['block_type_id'] = $BlockType;
                $thisfile_voc['blocks'][] = $ThisBlock;
            }
        } while (!feof($this->getid3->fp) && ($BlockType != 0));

        // Terminator block doesn't have size field, so seek back 3 spaces
        fseek($this->getid3->fp, -3, SEEK_CUR);

        ksort($thisfile_voc['blocktypes']);

        if (!empty($thisfile_voc['compressed_bits_per_sample'])) {
            $info['playtime_seconds'] = (($info['avdataend'] - $info['avdataoffset']) * 8) / ($thisfile_voc['compressed_bits_per_sample'] * $thisfile_audio['channels'] * $thisfile_audio['sample_rate']);
            $thisfile_audio['bitrate'] = (($info['avdataend'] - $info['avdataoffset']) * 8) / $info['playtime_seconds'];
        }

        return true;
    }

    /**
     * @staticvar array $VOCcompressionTypeLookup
     *
     * @param  type $index
     *
     * @return type
     */
    public function VOCcompressionTypeLookup($index)
    {
        static $VOCcompressionTypeLookup = array(
            0 => '8-bit',
            1 => '4-bit',
            2 => '2.6-bit',
            3 => '2-bit',
        );

        return (isset($VOCcompressionTypeLookup[$index]) ? $VOCcompressionTypeLookup[$index] : 'Multi DAC ('.($index - 3).') channels');
    }

    /**
     * @staticvar array $VOCwFormatLookup
     *
     * @param  type $index
     *
     * @return type
     */
    public function VOCwFormatLookup($index)
    {
        static $VOCwFormatLookup = array(
            0x0000 => '8-bit unsigned PCM',
            0x0001 => 'Creative 8-bit to 4-bit ADPCM',
            0x0002 => 'Creative 8-bit to 3-bit ADPCM',
            0x0003 => 'Creative 8-bit to 2-bit ADPCM',
            0x0004 => '16-bit signed PCM',
            0x0006 => 'CCITT a-Law',
            0x0007 => 'CCITT u-Law',
            0x2000 => 'Creative 16-bit to 4-bit ADPCM',
        );

        return (isset($VOCwFormatLookup[$index]) ? $VOCwFormatLookup[$index] : false);
    }

    /**
     * @staticvar array $VOCwFormatLookup
     *
     * @param  type $index
     *
     * @return type
     */
    public function VOCwFormatActualBitsPerSampleLookup($index)
    {
        static $VOCwFormatLookup = array(
            0x0000 => 8,
            0x0001 => 4,
            0x0002 => 3,
            0x0003 => 2,
            0x0004 => 16,
            0x0006 => 8,
            0x0007 => 8,
            0x2000 => 4,
        );

        return (isset($VOCwFormatLookup[$index]) ? $VOCwFormatLookup[$index] : false);
    }
}
