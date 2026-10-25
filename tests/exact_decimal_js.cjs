'use strict';
/* QRID_temporalSum and QRID_temporalMultiply, the twins of php/ExactDecimal.php, against
   a BigInt reference. tests/temporal_adversarial_php.php checks the PHP side against
   its own digit-at-a-time reference, so both runtimes are held to the exact answer.
   Since wargame P6 both work in limbs: the digit-at-a-time loops cost 85-130 ms per
   keystroke for an average over long values. */
const assert=require('assert');
global.window={};
global.document={addEventListener(){},getElementsByName(){return [];},readyState:'complete',body:{addEventListener(){}}};
require('../js/engine.js');
const {sum,multiply}=window.INSPIREUniversalValidator.exactDecimal;
let n=0;
function check(label,cond){n++;assert.ok(cond,label);}

let seed=20261025;
function rnd(max){seed=(seed*1103515245+12345)&0x7fffffff;return seed%max;}
function digits(len){let s='';for(let k=0;k<len;k++)s+=String(rnd(10));return s;}
/* Canonical decimal of a BigInt at a scale, as ExactDecimal formats it. */
function format(big,scale){
  const neg=big<0n;let s=(neg?-big:big).toString();
  if(scale){s=s.padStart(scale+1,'0');s=(s.slice(0,-scale)+'.'+s.slice(-scale)).replace(/0+$/,'').replace(/\.$/,'');}
  return (neg&&s!=='0'?'-':'')+s;
}
function parse(v){
  v=v.trim();const neg=v[0]==='-';v=v.replace(/^[+-]/,'');const [i,f='']=v.split('.');
  const b=BigInt((i+f)||'0');return {big:neg?-b:b,scale:f.length};
}
function refSum(values){
  const parsed=values.map(parse),scale=Math.max(0,...parsed.map(p=>p.scale));
  let total=0n;for(const p of parsed)total+=p.big*10n**BigInt(scale-p.scale);
  return format(total,scale);
}
function refMultiply(a,b){const x=parse(a),y=parse(b);return format(x.big*y.big,x.scale+y.scale);}
function number(maxLen){
  let s=digits(1+rnd(maxLen));if(rnd(4)===0)s='0'.repeat(1+rnd(3))+s;
  if(rnd(2)){const f=rnd(30);s+='.'+digits(f);}
  if(rnd(3)===0)s=(rnd(3)?'-':'+')+s;
  return s;
}

// The table of tests/temporal_adversarial_php.php, so a case added there has a twin here.
for(const [values,want] of [
  [['0.1','0.2'],'0.3'],[['-0.1','0.1'],'0'],[['+5','5.','.5'],'10.5'],[['-0','0.000'],'0'],
  [['999999999999999999','1'],'1000000000000000000'],[['9223372036854775807','1'],'9223372036854775808'],
  [['-9223372036854775808','-1'],'-9223372036854775809'],[['1e5'],null],[['0x10'],null],[[' 7 ','3'],'10'],
  [['100000000000000000000000000000','-99999999999999999999999999999.5'],'0.5'],
  [['0.000000000000000000000000000001','0.000000000000000000000000000002'],'0.000000000000000000000000000003'],
  [['12345678901234567','-12345678901234568'],'-1'],[['5','-5','0.00'],'0'],[['-1.5','-2.5'],'-4']
])check('sum '+JSON.stringify(values),sum(values)===want);

// Carries and borrows across 7-digit limbs, and a total that cancels and changes sign.
let bad=0;
for(let i=0;i<4000;i++){
  const values=[];for(let k=1+rnd(6);k>0;k--)values.push(number(rnd(4)?40:700));
  if(sum(values)!==refSum(values)&&bad++<3)console.error('sum',JSON.stringify(values));
}
for(const values of [['9999999.9999999','0.0000001'],['1'+'0'.repeat(40),'-0.000001'],['-'+'9'.repeat(39),'1'+'0'.repeat(39)],
  ['123456789012345678901234567890','-123456789012345678901234567890','-0.25'],['-10000000','9999999.5','0.5'],
  ['9','-0000000005'],['-0000000000000012','00000000000000000003.5'],['0000000000.000']]){
  if(sum(values)!==refSum(values)){bad++;console.error('sum edge',JSON.stringify(values));}
}
check('limb summation equals the BigInt sum on 4,005 lists',bad===0);

// The 4,096-character limit is checked after every value, on the total at the scale reached
// so far, its sign included, as ExactDecimal::sum checks its string.
const L='1'+digits(4089);
check('a zero that widens the total past 4,096 characters is the limit',sum([L,'0.0000000'])===null);
check('... and one that keeps it at 4,096 is not',sum([L,'0.000000'])===refSum([L,'0.000000']));
check('a negative total counts its sign against the limit',sum(['-'+'9'.repeat(4095),'-1'])===null&&sum(['9'.repeat(4095),'1'])==='1'+'0'.repeat(4095));
check('a total over the limit part way is the limit even if it comes back',sum(['9'.repeat(4096),'9'.repeat(4096),'-'+'9'.repeat(4096)])===null);
check('a value over 4,096 characters is the limit',sum(['1'.repeat(4097)])===null);
check('leading zeros do not count against the limit',sum(['00000000'+'9'.repeat(4088)])==='9'.repeat(4088));
check('a value that is not a decimal stops the sum',sum(['1','2a'])===null&&sum(['1',{}])===null);

bad=0;
for(let i=0;i<3000;i++){
  const a=number(i%100===0?2000:60),b=number(i%100===1?2000:(i%3?7:40));
  if(multiply(a,b)!==refMultiply(a,b)&&bad++<3)console.error('multiply',a,b);
}
for(const a of ['9999999','10000000','99999999999999','100000000000000','9'.repeat(2048)])for(const b of ['9999999','10000000','-1','9'.repeat(2048)]){
  if(multiply(a,b)!==refMultiply(a,b)){bad++;console.error('multiply edge',a.length,b.length);}
}
check('limb multiplication equals the BigInt product on 3,020 pairs',bad===0);
check('multiplication keeps decimal scale and sign',multiply('-0.25','0.4')==='-0.1'&&multiply('12.50','8')==='100'&&multiply('0.0','-5')==='0');
check('multiplication refuses operands over 4,096 digits together',multiply('9'.repeat(2049),'9'.repeat(2048))===null);

// Linear time: before P6 these took 5 s each.
const long=[];for(let i=0;i<700;i++)long.push(digits(4000)+'.'+digits(90));
let t=Date.now();const total=sum(long);const sumMs=Date.now()-t;
check('700 values of 4,090 digits sum exactly',total===refSum(long));
const u='7'+digits(2047),w='3'+digits(2047);
t=Date.now();const product=multiply(u,w);const mulMs=Date.now()-t;
check('two 2,048-digit values multiply exactly',product===refMultiply(u,w));
check('and both in well under a second (got '+sumMs+' ms and '+mulMs+' ms)',sumMs<1000&&mulMs<1000);
console.log(`exact_decimal_js: ${n} checks, 0 failures`);
