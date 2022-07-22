<?php

defined('BASEPATH') OR exit('No direct script access allowed');

date_default_timezone_set('America/Sao_Paulo');

class Chamado_model extends CI_Model {
    public function importaChamado($dados) {
        $msg = NULL;
        $this_model = new self; //re-instancia a classe Chamado_model para utilizar seus métodos na função 'registrar'

        /*
         * ------------ FUNCAO PARA IMPORTAR ------------------
         */
        function importar($inst, $sql_insert, $p_nome_fila, $p_id_usuario) {
            $id_novo_chamado = FALSE;

            $inst->db->trans_start();
            $inst->db->query($sql_insert); //registrando o chamado
            $query = $inst->db->query('SELECT id_chamado FROM chamado ORDER BY data_chamado DESC LIMIT 1');
            $linha = $query->row_array();
            $id_novo_chamado = $linha['id_chamado'];
            $inst->db->trans_complete();
            //$id_novo_chamado = $inst->db->insert_id(); // buscando o ID do chamado recem aberto

            $inst->db->query("INSERT INTO alteracao_chamado VALUES (NULL, " . $id_novo_chamado  . "," . //criando historico de alteracao
                             $p_id_usuario .", ' abriu o chamado na fila <b>" . 
                             $p_nome_fila . "</b>', NOW())");

            return $id_novo_chamado;
        }

        $q_buscaIdLocal = "SELECT l.PMSID FROM otrs_locations AS l WHERE l.name = '" . addslashes($dados['nome_local']) . "'";
        $r_id_local = $this->db->query($q_buscaIdLocal);

        /*
         * validando local
         */
        if ($r_id_local->num_rows() > 0) {
            $pms_id = $r_id_local->row()->PMSID;
            $complementoM = mb_strtoupper($dados['comp_local'], 'UTF-8');
            $resumoM = mb_strtoupper($dados['resumo_solicitacao'], 'UTF-8');

            //$id_ticket_otrs = $this->db->query("select id_ticket_triagem from triagem where id_triagem = " .$dados['id_triagem'])->row()->id_ticket_triagem;

            $q_insereChamado = <<<SQL
                                 INSERT INTO db_sigat.chamado (
                                       nome_solicitante_chamado,
                                       telefone_chamado,
                                       id_usuario_abertura_chamado,
                                       status_chamado,
                                       id_fila_chamado,
                                       data_chamado,
                                       ticket_chamado,
                                       id_ticket_chamado,
                                       complemento_chamado,
                                       resumo_chamado,
                                       data_encerramento_chamado,
                                       pms_id
                                 )
                                 VALUES (
                                       '{$dados['nome_solicitante']}',
                                       '{$dados['telefone']}',
                                       {$dados['id_usuario']},
                                       'ABERTO',
                                       1,
                                       NOW(),
                                       '{$dados['num_ticket']}',
                                       '{$dados['id_ticket']}',
                                       '{$complementoM}',
                                       '{$resumoM}',
                                       NULL,
                                       '{$pms_id}'
                                 )
                                 ;
                               SQL;

            // TODO resumo & complemento will be used as autocomplete in near future. Maybe a SQL View would do the job?
            if (strlen($resumoM) > 6)
                $this->db->query("INSERT resumo VALUES (NULL, '" . $resumoM . "')"); // cadastrando resumos

            if (strlen($complementoM) > 6)
                $this->db->query("INSERT complemento VALUES (NULL, '" . $complementoM . "')"); // cadastrando complementos

            $nome_fila = $this->db->query("SELECT nome_fila FROM fila WHERE id_fila = 1")->row()->nome_fila;
            if (!empty($dados['listaEquipamentos'])) {
                $novo_id = importar($this_model, $q_insereChamado, $nome_fila, $dados['id_usuario']);

                if($novo_id !== FALSE) {
                    /*
                     * ------------ LOG -------------------
                     */
                    $log = array(
                        'acao_evento' => 'INSERIR_CHAMADO',
                        'desc_evento' => 'ID CHAMADO: ' . $novo_id ,
                        'id_usuario_evento' => $_SESSION['id_usuario']
                    );
                    $this->db->insert('evento', $log);

                    foreach($dados['listaEquipamentos'] as $equip) { //registrando nas tabelas equipamento_chamado e, se necessario, na tabela equipamento
                        $busca_equip = $this->db->query("SELECT * FROM equipamento WHERE num_equipamento = '". $equip->Número ."'");
                        if ($busca_equip->num_rows() == 0) { //equipamento novo
                            $this->db->query("INSERT INTO equipamento VALUES ('". $equip->Número ."','". $equip->Descrição . "', NOW(), NULL, NULL)");
                        }
                        $this->db->query("INSERT INTO equipamento_chamado VALUES ('" . $equip->Número . "', 'ABERTO', NULL, NOW(), ". $novo_id .")");
                    }

                    // TODO usar anexos OTRS somente
                    // FIXME como eh feita de => para do anexo_otrs para que ele seja reconhecido no SIGAT
                    foreach($dados['anexos'] as $anexo) {
                        $this->db->query("INSERT INTO anexos_otrs(id_chamado_sigat, id_anexo_otrs) VALUES (" . $novo_id . ", " . $anexo->id_arquivo . ")");
                    }

                    // FIXME not used anymore. Still need be here!?
                    //$this->db->query("delete from anexos_otrs where id_chamado_sigat is NULL and id_triagem_sigat = " . $dados['id_triagem']); //deletando anexos descartados
                    //$this->db->query("update triagem set triado_triagem = 1 where id_triagem = " . $dados['id_triagem']); //marcando triagem como realizada

                    $msg = "";
                    $msg = "<div id=\"alerta\" class=\"alert alert-success\">";
                    $msg .= "<small class=\"float-right\">". date('G:i:s') . "</small>";
                    $msg .= "Importação concluída! Chamado n. "; 
                    $msg .= $novo_id . "<br /><a href=". base_url('/painel?v=triagem') . ">Voltar para o painel</a>";
                    $msg .= "</div>";

                    return array("novo_id" => $novo_id, "msg" => $msg);
                } else {
                    die($novo_id);
                }
            }
        } else {
            $msg .= "<div id=\"alerta\" class=\"alert alert-warning alert-dismissible\">" .
                 "<a href=\"#\" class=\"close\" data-dismiss=\"alert\" aria-label=\"close\">&times;</a>" .
                 "Local inválido!" .
                 "</div>";

            exit($msg);
        }
    }

    public function alteraChamado($dados) {
        $msg = NULL;
        $texto_alteracao = NULL;

        $search_name = addslashes($dados['nome_local']);
        $q_buscaLocal = <<<SQL
                        SELECT
                          PMSID,
                          name
                        FROM otrs_locations AS l
                        WHERE
                          l.name = '{$search_name}'
                        ;
                        SQL;
        $r_local = $this->db->query($q_buscaLocal);

        /*
         * validando o local
         */
        if ($r_local->num_rows() > 0) {
            $pms_id = $r_local->row()->PMSID;
            $location_name = $r_local->row()->name;
        } else {
            $msg .= "<div id=\"alerta\" class=\"alert alert-warning alert-dismissible\">" .
                "<a href=\"#\" class=\"close\" data-dismiss=\"alert\" aria-label=\"close\">&times;</a>" .
                "Local inválido!" .
                "</div>";

            exit($msg);
        }

        /*
         * ------ checando alteracoes no chamado ---------
         */

        $orig_qry = <<<SQL
                  SELECT
                    id_usuario_responsavel_chamado,
                    nome_solicitante_chamado,
                    telefone_chamado,
                    pms_id,
                    u.nome_usuario AS "nome_responsavel",
                    l.name AS "nome_local"
                  FROM chamado AS c
                  LEFT JOIN usuario AS u ON c.id_usuario_responsavel_chamado = u.id_usuario
                  LEFT JOIN otrs_locations AS l ON c.pms_id = l.PMSID
                  WHERE
                    c.id_chamado = {$dados['id_chamado']}
                  ;
                  SQL;
        $chamado_original = $this->db->query($orig_qry)->row();

        if ($dados['id_responsavel'] != NULL) { // se foi enviado algum id_responsavel...
            $q_alteraChamado = <<<SQL
                             UPDATE chamado SET
                               pms_id = {$pms_id},
                               telefone_chamado = '{$dados["telefone"]}',
                               nome_solicitante_chamado = '{$dados["nome_solicitante"]}',
                               id_usuario_responsavel_chamado = {$dados['id_responsavel']}
                             WHERE
                               id_chamado = {$dados['id_chamado']}
                             SQL;
        } else { // se nao...
            $q_alteraChamado = <<<SQL
                             UPDATE chamado SET
                               pms_id = {$pms_id},
                               telefone_chamado = '{$dados["telefone"]}',
                               nome_solicitante_chamado = '{$dados["nome_solicitante"]}'
                             WHERE
                               id_chamado = {$dados['id_chamado']}
                             SQL;
        }

        // // FIXME execute it twice?! See #L200
        // $this->db->query($q_alteraChamado); //executa a alteracao

        /*
         * removido inserção de interacao
         */

        // TODO alteracao_chamado ficaria mais eficiente sendo logado diretamente no banco e preferencialmente em uma tabela soh (eventos) aos inves de duas (alteracao_chamado e eventos)
        //inserindo na tabela alteracao
        if ($chamado_original->pms_id != $pms_id) {
            // $new_local_name = $this->db->query('SELECT l.name FROM otrs_location AS l WHERE l.PMSID = ' . $pms_id)->row()->name;

            $texto_alteracao .= 'alterou o local de <strong>' . $chamado_original->nome_local . '</strong>';
            $texto_alteracao .= ' para <strong>' . $location_name . '</strong></p>';
        }

        if ($chamado_original->telefone_chamado != $dados['telefone']) {
            $texto_alteracao .= 'alterou o telefone de <strong>' . $chamado_original->telefone_chamado . '</strong>';
            $texto_alteracao .= ' para <strong>' . $dados['telefone'] . '</strong></p>';
        }

        if ($chamado_original->nome_solicitante_chamado != $dados['nome_solicitante']) {
            $texto_alteracao .= 'alterou o solicitante de <strong>' . $chamado_original->nome_solicitante_chamado . '</strong>';
            $texto_alteracao .= ' para <strong>' . $dados['nome_solicitante'] . '</strong></p>';
        }

        if ($dados['id_responsavel'] != NULL) {
            // FIXME somente um if eh necessario
            if ($chamado_original->id_usuario_responsavel_chamado != $dados['id_responsavel']) {
                $novo_nome_responsavel = $this->db->query('SELECT nome_usuario FROM usuario WHERE id_usuario = ' . $dados['id_responsavel'])->row()->nome_usuario;

                if ($chamado_original->id_usuario_responsavel_chamado != NULL) { 
                    $texto_alteracao .= 'alterou o responsável de <strong>' . $chamado_original->nome_responsavel . '</strong>';
                    $texto_alteracao .= ' para <strong>' . $novo_nome_responsavel . '</strong>';
                } else { // se a alteracao do responsavel for de NULL para algum valor...
                    $texto_alteracao .= 'alterou o responsável';
                    $texto_alteracao .= ' para <strong>' . $novo_nome_responsavel . '</strong>';
                }
            }
        }

        // FIXME somente um if eh necessario...
        if($this->db->query($q_alteraChamado)) {
            if ($texto_alteracao != NULL) {
                $nova_alteracao = array (
                    'id_alteracao' => NULL,
                    'data_alteracao' => date('Y-m-d H:i:s'),
                    'texto_alteracao' => $texto_alteracao,
                    'id_chamado_alteracao' => $dados['id_chamado'],
                    'id_usuario_alteracao' => $dados['id_usuario'],
                 );
                 $this->db->insert('alteracao_chamado',$nova_alteracao);

                 // ------------ LOG -------------------
                 $log = array(
                    'acao_evento' => 'ALTERAR_CHAMADO',
                    'desc_evento' => 'ID CHAMADO: ' . $dados['id_chamado'],
                    'id_usuario_evento' => $_SESSION['id_usuario']
                );
                $this->db->insert('evento', $log);
                // -------------- /LOG ----------------
            }
        //     exit($msg);
        }
    }

    public function encerraChamado($dados) {

        

        $usuario = $this->db->query('select autorizacao_usuario, encerramento_usuario from usuario where id_usuario = ' 
                                    . $dados['id_usuario']);


        if($usuario->row()->autorizacao_usuario >= 3 && $usuario->row()->encerramento_usuario == 1) {
            
            $q_encerraChamado = $this->db->query("update chamado set status_chamado = 'ENCERRADO', data_encerramento_chamado = NOW() where id_chamado = " . $dados['id_chamado']);

            if($q_encerraChamado) {

                $this->db->query("insert into interacao values(NULL, 'ENC', NOW(), ' encerrou o chamado'," 
                . $dados['id_chamado'] . "," . $dados['id_usuario'] . " ,NULL,NULL)"); //inserindo a interacao


                // ------------ LOG -------------------

                $log = array(
                    'acao_evento' => 'ENCERRAR_CHAMADO',
                    'desc_evento' => 'ID CHAMADO: ' . $dados['id_chamado'],
                    'id_usuario_evento' => $_SESSION['id_usuario']
                );
                
                $this->db->insert('evento', $log);

                // -------------- /LOG ----------------

                
    
    
            }

            
        } else {

            header("HTTP/1.1 403 Forbidden");
        }
        
        
    }

    public function devolveChamado($p_id_chamado) {

        $ticket = $this->buscaTicketTriagem($p_id_chamado);
        $this->db->query("delete from triagem where id_triagem = " . $p_id_chamado);
        $this->db->query("delete from anexos_otrs where id_chamado_sigat = " . $p_id_chamado);

        $this->db->query("insert into alteracao_chamado ".
                         "values(NULL," . $p_id_chamado . 
                         "," . $_SESSION['id_usuario'] .
                         ",'<b>devolveu o ticket ".  $ticket . " para o OTRS</b>',NOW())");

         // ------------ LOG -------------------

         $log = array(
            'acao_evento' => 'DEVOLVER_TICKET',
            'desc_evento' => 'ID CHAMADO: ' . $p_id_chamado . ' - TICKET: ' . $ticket,
            'id_usuario_evento' => $_SESSION['id_usuario']
        );
        
        $this->db->insert('evento', $log);

        // -------------- /LOG ----------------


    }

    // FIXME ainda é usado?
    public function buscaTicketTriagem($id_triagem) {

        $result = $this->db->query("select ticket_triagem from triagem where id_triagem = " . $id_triagem);

        return $result->row()->ticket_triagem;
    }


    public function buscaChamado($id_chamado, $status = '') {
        // FIXME esta query não é exibida ?! Talvez na geração de termos
        $q_buscaChamado = <<<SQL
                        SELECT
                          c.id_ticket_chamado,
                          c.id_chamado,
                          f.id_fila,
                          c.ticket_chamado,
                          c.nome_solicitante_chamado,
                          c.telefone_chamado,
                          c.prioridade_chamado,
                          -- CONCAT('--- ', l.name, '+++') AS "nome_local",
                          l.name AS "nome_local",
                          DATE_FORMAT(c.data_chamado, '%d/%m/%Y - %H:%i:%s') AS data_chamado,
                          u.id_usuario AS "id_responsavel",
                          f.nome_fila AS "nome_fila_chamado"
                        FROM chamado AS c
                        LEFT JOIN otrs_locations AS l ON c.pms_id = l.PMSID
                        LEFT JOIN fila AS f ON c.id_fila_chamado = f.id_fila
                        LEFT JOIN usuario AS u ON c.id_usuario_responsavel_chamado = u.id_usuario
                        WHERE
                          c.id_chamado = {$id_chamado}
                        ;
        SQL;
        $result['chamado'] = $this->db->query($q_buscaChamado)->row();

        // FIXME esta query não é exibida?
        $q_buscaEquipamentos = <<<SQL
            SELECT
              -- CONCAT(' ==> ', e.num_equipamento, ' <--'),
              -- CONCAT('+++ ', e.descricao_equipamento, ' +++')
              e.num_equipamento,
              e.descricao_equipamento
            FROM equipamento AS e, equipamento_chamado
            WHERE
              equipamento_chamado.id_chamado_equipamento = {$id_chamado}
              AND status_equipamento_chamado = '{$status}'
              AND equipamento_chamado.num_equipamento_chamado = e.num_equipamento
            ;
        SQL;
        $result['equipamentos'] = $this->db->query($q_buscaEquipamentos)->result();

        if (isset($result['chamado'])) {
            $result['icone'] = $this->db->query(
                "SELECT icone_fila from fila f 
                INNER JOIN chamado c ON(f.id_fila = c.id_fila_chamado) 
                WHERE id_chamado = ". $id_chamado)->row()->icone_fila;
        }

        return $result;
    }

    public function buscaHistoricoChamado($id_chamado) {
        $q_buscaHistorico = "SELECT u.nome_usuario, a.texto_alteracao, a.data_alteracao FROM alteracao_chamado AS a, usuario AS u
        WHERE u.id_usuario = a.id_usuario_alteracao AND id_chamado_alteracao =". $id_chamado . " ORDER BY a.data_alteracao DESC LIMIT 50";

        return $this->db->query($q_buscaHistorico)->result();   


       
    }

    public function priorizaChamado($id_chamado) {
        
        $prioridade = $this->db->query("SELECT prioridade_chamado from chamado WHERE id_chamado = " . $id_chamado)->row()->prioridade_chamado;
        $nova_prioridade = $prioridade == 1 ? 0 : 1;
        $this->db->query("update chamado set prioridade_chamado = " . $nova_prioridade . " WHERE id_chamado = " . $id_chamado);
    }


}

?>